<?php

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsAppGraphException;
use App\Exceptions\WhatsAppOAuthException;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppOauthState;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * All Meta OAuth/Graph calls behind "Connect WhatsApp": state issuance/
 * consumption, Embedded Signup code exchange, WABA/phone-number
 * verification, and WABA-level webhook subscription. Structurally mirrors
 * FacebookOAuthService (same state-security concept, same "never trust a
 * submitted id, re-verify via a fresh Graph call" posture) but is NOT the
 * same class and does not call into it — WhatsApp's actual Graph API shape
 * differs from classic Facebook Login in ways that matter:
 *
 *  - Embedded Signup's code exchange has no redirect_uri (there was no
 *    browser redirect to register one for — the whole flow runs inside a
 *    JS SDK popup on an already-loaded, already-tenant-authenticated page).
 *  - There is no "list of Pages" equivalent — the analogous resource is a
 *    WABA's phone_numbers, fetched from the WABA the tenant picked inside
 *    Meta's own popup UI, not from a manually-typed id.
 *  - Subscribing to webhook events happens at the WABA level
 *    (/{waba-id}/subscribed_apps), not per-phone-number.
 */
class WhatsAppOAuthService
{
    /** Hard cap on /phone_numbers pagination — mirrors FacebookOAuthService::MAX_PAGE_LISTING_PAGES's guard against an unbounded loop on a malformed/looping cursor; a WABA realistically has far fewer than this many numbers. */
    protected const MAX_PAGE_LISTING_PAGES = 10;

    protected string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/'.config('whatsapp.graph_version');
    }

    /**
     * Single-use, expiring, tenant+user-bound CSRF state for the Embedded
     * Signup popup. Reuses an existing unexpired/unused state for the same
     * tenant+user instead of always minting a new row — unlike Facebook's
     * equivalent (minted once per explicit "Connect" click, which redirects
     * away immediately), this state is embedded directly into the Settings
     * page on every render while disconnected, so always-mint would leave a
     * fresh unused row behind on every page view a tenant makes without
     * clicking Connect.
     */
    public function currentOrNewState(Tenant $tenant, User $user, string $purpose = 'whatsapp'): WhatsAppOauthState
    {
        $existing = WhatsAppOauthState::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        return $existing ?: $this->createState($tenant, $user, $purpose);
    }

    public function createState(Tenant $tenant, User $user, string $purpose = 'whatsapp'): WhatsAppOauthState
    {
        return WhatsAppOauthState::create([
            'state' => bin2hex(random_bytes(32)), // 64 hex chars, cryptographically random
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(config('whatsapp.state_ttl_minutes')),
            'created_at' => now(),
        ]);
    }

    /**
     * Validates and atomically consumes a state token. The DB row is the
     * sole source of truth for which tenant/user this belongs to — nothing
     * from the request itself is trusted. Identical logic to
     * FacebookOAuthService::validateAndConsumeState(), including the
     * reason-coded exception and the atomic single-UPDATE-wins consume that
     * closes the replay race — this genuinely is the same security concept,
     * per this phase's explicit instruction to reuse it as-is.
     */
    public function validateAndConsumeState(?string $token, ?int $authenticatedUserId): WhatsAppOauthState
    {
        if (! $token) {
            throw new WhatsAppOAuthException('missing_state', 'No state parameter supplied.');
        }

        $state = WhatsAppOauthState::where('state', $token)->first();

        if (! $state) {
            throw new WhatsAppOAuthException('unknown_state', 'State token not recognized.');
        }

        if ($state->used_at) {
            throw new WhatsAppOAuthException('already_used', 'State token already used (replay).');
        }

        if ($state->expires_at->isPast()) {
            throw new WhatsAppOAuthException('expired_state', 'State token expired.');
        }

        if ($authenticatedUserId !== null && (int) $state->user_id !== $authenticatedUserId) {
            throw new WhatsAppOAuthException('user_mismatch', 'Authenticated user does not match the user who started this connection.');
        }

        $consumed = DB::table('whatsapp_oauth_states')
            ->where('id', $state->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($consumed === 0) {
            throw new WhatsAppOAuthException('already_used', 'State token already used (race).');
        }

        return $state;
    }

    /**
     * Embedded Signup's code exchange — deliberately no redirect_uri
     * parameter, unlike FacebookOAuthService::exchangeCodeForShortLivedToken().
     * There was no browser redirect in this flow to register a URI for; the
     * `code` comes from the JS SDK's FB.login() callback inside a popup on
     * a page that never navigated away.
     */
    public function exchangeCodeForAccessToken(string $code): array
    {
        $response = Http::get("{$this->base}/oauth/access_token", [
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'code' => $code,
        ]);

        $this->throwIfFailed($response);

        return [
            'access_token' => $response->json('access_token'),
            'expires_in' => $response->json('expires_in'), // may be absent — Embedded Signup tokens are often already long-lived/non-expiring
        ];
    }

    /**
     * Harmless even when the token from exchangeCodeForAccessToken() is
     * already long-lived — re-exchanging a long-lived token just returns
     * another long-lived token, not an error — kept for consistency with
     * how every other token in this codebase gets this same treatment
     * rather than relying on undocumented Embedded Signup token-lifetime
     * behavior varying by WABA setup.
     */
    public function exchangeForLongLivedToken(string $token): array
    {
        $response = Http::get("{$this->base}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'fb_exchange_token' => $token,
        ]);

        $this->throwIfFailed($response);

        return [
            'access_token' => $response->json('access_token'),
            'expires_in' => (int) $response->json('expires_in', 5184000), // ~60 days fallback, same as FacebookOAuthService
        ];
    }

    /** Best-effort display label only — never used for any authorization decision (see WhatsAppConnectController). */
    public function getWabaName(string $wabaId, string $accessToken): ?string
    {
        $response = Http::get("{$this->base}/{$wabaId}", [
            'fields' => 'id,name',
            'access_token' => $accessToken,
        ]);

        $this->throwIfFailed($response);

        return $response->json('name');
    }

    /**
     * The actual security-critical check (instruction: "the server must
     * validate the resulting WABA/phone-number relationship") — fetches the
     * WABA's real phone_numbers directly from Meta with the newly obtained
     * token and returns the entry matching $phoneNumberId, or null if that
     * id isn't actually among this WABA's numbers. Never trusts the
     * phone_number_id the browser/postMessage event claimed on its own;
     * this is what confirms it. Same "never trust the submitted id, re-fetch
     * fresh from Graph" posture as FacebookConnectController::connect()'s
     * collect($pages)->firstWhere('id', $pageId) check.
     *
     * @return array{id:string, display_phone_number?:string, verified_name?:string, quality_rating?:string}|null
     */
    public function findPhoneNumber(string $wabaId, string $phoneNumberId, string $accessToken): ?array
    {
        $url = "{$this->base}/{$wabaId}/phone_numbers";
        $query = [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
            'access_token' => $accessToken,
            'limit' => 100,
        ];

        for ($i = 0; $i < self::MAX_PAGE_LISTING_PAGES; $i++) {
            $response = $query !== null ? Http::get($url, $query) : Http::get($url);

            $this->throwIfFailed($response);

            $match = collect($response->json('data', []))->firstWhere('id', $phoneNumberId);

            if ($match) {
                return $match;
            }

            $next = $response->json('paging.next');

            if (! $next) {
                return null;
            }

            $url = $next;
            $query = null;
        }

        return null;
    }

    public function subscribeWabaToWebhook(string $wabaId, string $accessToken): bool
    {
        // No subscribed_fields parameter — unlike Messenger's per-Page
        // subscription, WABA-level subscription just attaches this app to
        // receive whatever fields the App's Webhooks product is already
        // configured for (set up once in the Meta App dashboard, per Phase
        // 2). Passing/expanding fields here isn't necessary for a
        // functional connection and isn't part of this phase's scope.
        $response = Http::asForm()->post("{$this->base}/{$wabaId}/subscribed_apps", [
            'access_token' => $accessToken,
        ]);

        $this->throwIfFailed($response);

        return (bool) $response->json('success', false);
    }

    /** Best-effort — failures here are logged by the caller, never fatal to a disconnect. Same query-string-token reasoning as FacebookOAuthService::unsubscribePageFromWebhook(). */
    public function unsubscribeWabaFromWebhook(string $wabaId, string $accessToken): bool
    {
        $url = "{$this->base}/{$wabaId}/subscribed_apps?".http_build_query([
            'access_token' => $accessToken,
        ]);

        $response = Http::delete($url);

        return $response->successful() && (bool) $response->json('success', false);
    }

    protected function throwIfFailed(Response $response): void
    {
        $error = $response->json('error');

        if ($response->successful() && ! $error) {
            return;
        }

        throw new WhatsAppGraphException((array) ($error ?: ['message' => 'Unknown WhatsApp Cloud API error']));
    }
}
