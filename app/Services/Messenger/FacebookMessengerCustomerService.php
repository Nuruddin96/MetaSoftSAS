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
 * blocked or corrupted by this. Every write path only sets fields Graph
 * actually returned this time; a stale/expired token or a temporary Graph
 * outage degrades to "keep whatever we already had," never to null.
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
     * record, or the unchanged existing one if the fetch failed, or null
     * only when database/sql/chunk28.sql hasn't been imported yet on this
     * environment. withoutGlobalScopes()+explicit tenant_id throughout,
     * same reasoning as every other identity-adjacent write in this
     * codebase (e.g. WhatsAppSendService) — callers include the webhook,
     * which never has app('currentTenant') bound.
     */
    public function syncCustomerProfile(int $tenantId, ?int $facebookPageId, string $psid, string $pageAccessToken): ?MessengerCustomer
    {
        if (! MessengerCustomer::tablesReady()) {
            return null;
        }

        $identity = MessengerCustomer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('psid', $psid)->first();

        try {
            $profile = $this->api->getProfile($psid, $pageAccessToken);
        } catch (\Throwable $e) {
            // Same "never log $e->getMessage()" caution used throughout this
            // codebase's Graph API error handling — a transport exception's
            // message can echo request details (e.g. the access token in a
            // failed request's URI, depending on the underlying HTTP client).
            Log::warning('Messenger identity sync: profile fetch failed.', [
                'tenant_id' => $tenantId,
                'psid' => $psid,
                'exception' => get_class($e),
            ]);

            return $identity;
        }

        if (! $profile) {
            Log::warning('Messenger identity sync: Graph API returned an unsuccessful response (invalid/expired token, permission error, or rate limit).', [
                'tenant_id' => $tenantId,
                'psid' => $psid,
            ]);

            return $identity;
        }

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
        // A 200 response is still a definitive answer even when it carries
        // no usable name/photo (a customer who genuinely has neither), so
        // identity_fetched_at always advances here — that's what stops
        // needsRefresh() from retrying every single message for such a
        // customer; only a thrown exception or a failed HTTP response
        // (the two return-early branches above) leave it untouched so a
        // transient failure gets retried on the next message instead.
        $attributes = array_filter([
            'facebook_page_id' => $facebookPageId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name,
            'profile_pic_url' => $profilePic,
        ], fn ($v) => $v !== null);

        $attributes['identity_fetched_at'] = now();

        if ($profilePic) {
            $attributes['profile_pic_fetched_at'] = now();
        }

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

            return $identity;
        }
    }
}
