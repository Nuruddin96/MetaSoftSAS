<?php

namespace App\Services\Messenger;

use App\Models\MessengerCustomer;
use Illuminate\Support\Facades\Log;

/**
 * Canonical fetch+persist path for Facebook Messenger customer identity
 * (name + profile photo) — the ONLY place that calls MessengerApi::
 * getProfile() for identity purposes, called from MessengerWebhookController
 * (opportunistically, on an inbound message, gated by needsRefresh()) and
 * from the manual/batch sync actions (MessengerInboxController::
 * syncCustomers()/refreshProfile()).
 *
 * Never throws and never lets a Facebook failure erase a previously-good
 * identity — a webhook handling an actual customer message must never be
 * blocked or corrupted by this. Every write path only sets name/photo
 * fields Graph actually returned this time; a stale/expired token or a
 * temporary Graph outage degrades to "keep whatever we already had for
 * those fields," never to null.
 *
 * IMPORTANT (see PROJECT_KNOWLEDGE.md / the production identity-sync
 * incident this fixes): persistence of the PSID record itself
 * (tenant_id/facebook_page_id/psid/identity_fetched_at) is UNCONDITIONAL —
 * it happens on every call, regardless of whether Graph succeeds, returns
 * a permission/token error (code 190, code 100/subcode 33, or anything
 * else), or the HTTP call throws outright. Only the name/photo fields are
 * conditional on Graph actually returning them. This is deliberate: a
 * customer whose profile Meta will never let us read must still exist as
 * a known PSID (so the inbox can show *something* other than silently
 * losing them), and identity_fetched_at still advances on failure so
 * needsRefresh() naturally backs off a permanently-failing PSID instead of
 * re-querying Graph on every single incoming message.
 */
class FacebookMessengerCustomerService
{
    public function __construct(protected MessengerApi $api) {}

    /**
     * True when this identity has never been fetched, or is older than the
     * configured refresh window — the gate that keeps this from calling
     * Graph on every single message (see MESSENGER_PROFILE_REFRESH_HOURS /
     * config('messenger.profile_refresh_hours')). Callers that want an
     * unconditional refetch (the explicit "Refresh Profile" action) call
     * syncCustomerProfile() directly instead of consulting this first.
     */
    public function needsRefresh(?MessengerCustomer $identity): bool
    {
        if (! $identity || ! $identity->identity_fetched_at) {
            return true;
        }

        $hours = (int) config('messenger.profile_refresh_hours', 24);

        return $identity->identity_fetched_at->lt(now()->subHours($hours));
    }

    /**
     * Fetches from Graph and persists. Returns the up-to-date identity
     * record — including on a Graph failure, see the class docblock — or
     * null only when database/sql/chunk28.sql hasn't been imported yet on
     * this environment. withoutGlobalScopes()+explicit tenant_id
     * throughout, same reasoning as every other identity-adjacent write in
     * this codebase (e.g. WhatsAppSendService) — callers include the
     * webhook, which never has app('currentTenant') bound.
     */
    public function syncCustomerProfile(int $tenantId, ?int $facebookPageId, string $psid, string $pageAccessToken): ?MessengerCustomer
    {
        if (! MessengerCustomer::tablesReady()) {
            return null;
        }

        $identity = MessengerCustomer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('psid', $psid)->first();

        // Always attempted regardless of what Graph does — see class
        // docblock. facebook_page_id/identity_fetched_at are the minimum
        // record of "this PSID messaged us via this Page at this time,"
        // independent of whether Graph ever tells us who they are.
        $baseAttributes = [
            'facebook_page_id' => $facebookPageId,
            'identity_fetched_at' => now(),
        ];

        try {
            $response = $this->api->getProfile($psid, $pageAccessToken);
        } catch (\Throwable $e) {
            // Same "never log $e->getMessage()" caution used throughout this
            // codebase's Graph API error handling — a transport exception's
            // message can echo request details (e.g. the access token in a
            // failed request's URI, depending on the underlying HTTP client).
            Log::warning('Messenger identity sync: profile fetch failed (transport error).', [
                'tenant_id' => $tenantId,
                'psid' => $psid,
                'exception' => get_class($e),
            ]);

            return $this->persistIdentity($tenantId, $psid, $baseAttributes, $identity);
        }

        if (! $response->successful()) {
            $error = $response->json('error') ?? [];

            Log::warning('Messenger identity sync: Graph API returned an unsuccessful response.', [
                'tenant_id' => $tenantId,
                'psid' => $psid,
                'http_status' => $response->status(),
                'error_code' => $error['code'] ?? null,
                'error_type' => $error['type'] ?? null,
                'error_message' => $error['message'] ?? null,
                'error_subcode' => $error['error_subcode'] ?? null,
            ]);

            return $this->persistIdentity($tenantId, $psid, $baseAttributes, $identity);
        }

        $profile = $response->json();

        $firstName = is_string($profile['first_name'] ?? null) ? $profile['first_name'] : null;
        $lastName = is_string($profile['last_name'] ?? null) ? $profile['last_name'] : null;
        $profilePic = is_string($profile['profile_pic'] ?? null) ? $profile['profile_pic'] : null;
        $name = trim(($firstName ?? '').' '.($lastName ?? '')) ?: null;

        if (! $name && ! $profilePic) {
            Log::info('Messenger identity sync: profile fetch returned no usable name or photo.', [
                'tenant_id' => $tenantId,
                'psid' => $psid,
                'response_had_first_name' => isset($profile['first_name']),
                'response_had_last_name' => isset($profile['last_name']),
                'response_had_profile_pic' => isset($profile['profile_pic']),
            ]);
        }

        // array_filter drops nulls — a field Graph didn't return this time
        // never overwrites a previously-stored value for that same field.
        $attributes = $baseAttributes + array_filter([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'profile_pic_url' => $profilePic,
        ], fn ($v) => $v !== null);

        if ($profilePic) {
            $attributes['profile_pic_fetched_at'] = now();
        }

        return $this->persistIdentity($tenantId, $psid, $attributes, $identity);
    }

    /**
     * The single write path every syncCustomerProfile() exit funnels
     * through — success, Graph error, or transport exception all reach
     * this with whatever attributes are known at that point (at minimum
     * $baseAttributes). $fallback is only returned if the write itself
     * fails (e.g. a DB outage), matching the previous behavior of never
     * losing a prior identity to a transient persistence failure.
     */
    protected function persistIdentity(int $tenantId, string $psid, array $attributes, ?MessengerCustomer $fallback): ?MessengerCustomer
    {
        try {
            return MessengerCustomer::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'psid' => $psid],
                $attributes
            );
        } catch (\Throwable $e) {
            Log::warning('Messenger identity sync: failed to persist identity record.', [
                'tenant_id' => $tenantId,
                'psid' => $psid,
                'exception' => get_class($e),
            ]);

            return $fallback;
        }
    }
}
