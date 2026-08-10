<?php

namespace App\Services\Facebook;

use App\Exceptions\FacebookGraphException;
use App\Exceptions\FacebookOAuthException;
use App\Models\FacebookOauthState;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * All Meta OAuth/Graph calls behind "Connect Facebook": authorization URL,
 * state issuance/consumption, code exchange, long-lived token exchange,
 * managed-Pages listing, and per-Page webhook subscription. One Meta App
 * serves every tenant — nothing here is tenant-specific beyond the data
 * passed in, the same shape as CourierManager/DomainManager elsewhere in
 * this codebase.
 */
class FacebookOAuthService
{
    /** Hard cap on /me/accounts pagination — 100/page, so 10 covers up to 1,000 Pages; guards against an unbounded loop on a malformed/looping cursor. */
    protected const MAX_PAGE_LISTING_PAGES = 10;

    protected string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/'.config('facebook.graph_version');
    }

    /** Single-use, expiring, tenant+user-bound CSRF state for the OAuth dialog. */
    public function createState(Tenant $tenant, User $user, string $purpose = 'messenger'): FacebookOauthState
    {
        return FacebookOauthState::create([
            'state' => bin2hex(random_bytes(32)), // 64 hex chars, cryptographically random
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(config('facebook.state_ttl_minutes')),
            'created_at' => now(),
        ]);
    }

    public function authorizationUrl(FacebookOauthState $state): string
    {
        $params = [
            'client_id' => config('facebook.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state->state,
            'scope' => implode(',', config('facebook.scopes')),
            'response_type' => 'code',
        ];

        return 'https://www.facebook.com/'.config('facebook.graph_version').'/dialog/oauth?'.http_build_query($params);
    }

    public function redirectUri(): string
    {
        return config('facebook.redirect_uri') ?: route('facebook.callback');
    }

    /**
     * Validates and atomically consumes a state token. The DB row is the
     * sole source of truth for which tenant/user this belongs to — nothing
     * from the request itself is trusted. Throws with a specific `reason`
     * for every rejection path so callers can log without ever logging the
     * token. $authenticatedUserId may be null (e.g. session cookie not
     * visible at the central callback host under subdomain tenancy mode) —
     * when null, the state row's own binding remains authoritative and this
     * check is simply skipped rather than treated as a hard failure.
     */
    public function validateAndConsumeState(?string $token, ?int $authenticatedUserId): FacebookOauthState
    {
        if (! $token) {
            throw new FacebookOAuthException('missing_state', 'No state parameter on callback.');
        }

        $state = FacebookOauthState::where('state', $token)->first();

        if (! $state) {
            throw new FacebookOAuthException('unknown_state', 'State token not recognized.');
        }

        if ($state->used_at) {
            throw new FacebookOAuthException('already_used', 'State token already used (replay).');
        }

        if ($state->expires_at->isPast()) {
            throw new FacebookOAuthException('expired_state', 'State token expired.');
        }

        if ($authenticatedUserId !== null && (int) $state->user_id !== $authenticatedUserId) {
            throw new FacebookOAuthException('user_mismatch', 'Authenticated user does not match the user who started this connection.');
        }

        // Atomic single-use: only the request that wins this UPDATE proceeds,
        // closing the race between a duplicate/replayed callback request and
        // the checks above.
        $consumed = DB::table('facebook_oauth_states')
            ->where('id', $state->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        if ($consumed === 0) {
            throw new FacebookOAuthException('already_used', 'State token already used (race).');
        }

        return $state;
    }

    /** Exchanged immediately for the long-lived token — never persisted. */
    public function exchangeCodeForShortLivedToken(string $code): string
    {
        $response = Http::get("{$this->base}/oauth/access_token", [
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        $this->throwIfFailed($response);

        return $response->json('access_token');
    }

    /** @return array{access_token:string, expires_in:int} */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get("{$this->base}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $this->throwIfFailed($response);

        return [
            'access_token' => $response->json('access_token'),
            'expires_in' => (int) $response->json('expires_in', 5184000), // ~60 days fallback
        ];
    }

    public function getProfile(string $userAccessToken): array
    {
        $response = Http::get("{$this->base}/me", [
            'fields' => 'id,name',
            'access_token' => $userAccessToken,
        ]);

        $this->throwIfFailed($response);

        return $response->json();
    }

    /**
     * Follows Meta's `paging.next` cursor so a Facebook user managing more
     * than 100 Pages doesn't silently lose some from the picker — capped at
     * MAX_PAGE_LISTING_PAGES requests so a malformed/looping cursor can
     * never turn into an unbounded request loop.
     *
     * @return array<int, array{id:string, name:string, access_token:string, category?:string}>
     */
    public function getManagedPages(string $userAccessToken): array
    {
        $pages = [];
        $url = "{$this->base}/me/accounts";
        $query = [
            'fields' => 'id,name,access_token,category',
            'access_token' => $userAccessToken,
            'limit' => 100,
        ];

        for ($i = 0; $i < self::MAX_PAGE_LISTING_PAGES; $i++) {
            $response = $query !== null ? Http::get($url, $query) : Http::get($url);

            $this->throwIfFailed($response);

            $pages = array_merge($pages, $response->json('data', []));

            $next = $response->json('paging.next');

            if (! $next) {
                break;
            }

            // paging.next is already a complete URL with its own query
            // string (including a fresh access_token) — don't re-append $query.
            $url = $next;
            $query = null;
        }

        return $pages;
    }

    public function subscribePageToWebhook(string $pageId, string $pageAccessToken): bool
    {
        $response = Http::asForm()->post("{$this->base}/{$pageId}/subscribed_apps", [
            'subscribed_fields' => 'messages,messaging_postbacks,message_deliveries,message_reads,message_echoes',
            'access_token' => $pageAccessToken,
        ]);

        $this->throwIfFailed($response);

        return (bool) $response->json('success', false);
    }

    /**
     * Best-effort — failures here are logged by the caller, never fatal to
     * a disconnect. Meta's documented pattern for DELETE endpoints expects
     * access_token as a query parameter; Laravel's Http::delete($url, $data)
     * sends $data as the JSON request body by default (same as post/put),
     * which Meta's Graph API would not treat as authentication — so the
     * token is appended to the URL explicitly here instead of passed as the
     * $data argument.
     */
    public function unsubscribePageFromWebhook(string $pageId, string $pageAccessToken): bool
    {
        $url = "{$this->base}/{$pageId}/subscribed_apps?".http_build_query([
            'access_token' => $pageAccessToken,
        ]);

        $response = Http::delete($url);

        return $response->successful() && (bool) $response->json('success', false);
    }

    /**
     * Meta can return an `error` object embedded in a 200 OK body for some
     * failure modes, not only via a non-2xx HTTP status — checking
     * successful() alone would let that case through as if it were a valid
     * token/page response. Both are treated as failure here.
     */
    protected function throwIfFailed(Response $response): void
    {
        $error = $response->json('error');

        if ($response->successful() && ! $error) {
            return;
        }

        throw new FacebookGraphException((array) ($error ?: ['message' => 'Unknown Facebook Graph API error']));
    }
}
