<?php

namespace App\Console\Commands;

use App\Exceptions\FacebookGraphException;
use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Services\Facebook\FacebookOAuthService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Root-cause fix for "Messenger stops receiving new messages until Facebook
 * is disconnected and reconnected": exchangeForLongLivedToken() was only
 * ever called once, at the moment of FacebookOAuthCallbackController::
 * handle() — nothing anywhere in this codebase refreshed a Facebook user
 * token before its ~60-day expiry, or re-verified a Page's webhook
 * subscription after initial connect. Once the token silently died (or
 * Meta otherwise invalidated the grant), Page delivery stopped with no
 * visible signal — facebook_pages.status stayed 'active' forever, because
 * nothing ever probed Meta to notice the difference. Reconnecting "fixed"
 * it only because that's the one code path that both issues a fresh token
 * AND explicitly re-calls subscribed_apps.
 *
 * This command closes that gap on a schedule (see routes/console.php),
 * NOT on every webhook event — deliberately not "subscribe on every
 * message," per the explicit instruction that led to this fix:
 *   1. Proactively refresh any connection's long-lived token while it
 *      still has time left, before it can expire under normal operation.
 *   2. Re-verify every active Page against a FRESH /me/accounts fetch
 *      (never trust the stored page_access_token blindly — same "never
 *      trust a stored/submitted id" posture FacebookConnectController::
 *      connect() already uses) and re-subscribe it to the webhook.
 *   3. If a token turns out to already be dead (Meta's standard code 190),
 *      flip the affected Page(s) to needs_reconnect so the tenant sees an
 *      accurate status in Settings instead of a silent, indefinite outage
 *      — the same status the manual reconnect flow already surfaces.
 *
 * 100% composed from FacebookOAuthService methods that already exist and
 * are already tested (exchangeForLongLivedToken, getManagedPages,
 * subscribePageToWebhook) — no new Graph API behavior is introduced here.
 */
class RefreshFacebookPageSubscriptions extends Command
{
    protected $signature = 'facebook:refresh-connections {--dry-run : Report what would happen without calling Meta}';

    protected $description = 'Proactively refreshes Facebook long-lived tokens before they expire and re-subscribes connected Pages to the webhook';

    /** Refresh a token once it has this many days or fewer left — well before its ~60-day lifetime, so a daily schedule run always catches it in time. */
    protected const REFRESH_WITHIN_DAYS = 10;

    public function handle(FacebookOAuthService $fb): int
    {
        if (! FacebookPage::tablesReady()) {
            $this->error('Facebook OAuth tables are not fully migrated yet — nothing to do.');

            return self::FAILURE;
        }

        $connections = FacebookConnection::withoutGlobalScopes()->get();

        if ($connections->isEmpty()) {
            $this->info('No Facebook connections found.');

            return self::SUCCESS;
        }

        $this->info("Checking {$connections->count()} Facebook connection(s).");

        foreach ($connections as $connection) {
            $this->processConnection($connection, $fb);
        }

        return self::SUCCESS;
    }

    protected function processConnection(FacebookConnection $connection, FacebookOAuthService $fb): void
    {
        $dryRun = (bool) $this->option('dry-run');
        // Carbon's diffInDays() sign semantics are easy to get backwards —
        // isPast()/lessThanOrEqualTo() below are unambiguous instead.
        $needsRefresh = ! $connection->token_expires_at
            || $connection->token_expires_at->isPast()
            || $connection->token_expires_at->lessThanOrEqualTo(now()->addDays(self::REFRESH_WITHIN_DAYS));

        if ($needsRefresh) {
            if ($dryRun) {
                $this->line("[dry-run] would refresh token: tenant_id={$connection->tenant_id}");
            } else {
                try {
                    $refreshed = $fb->exchangeForLongLivedToken($connection->user_access_token);
                    $connection->update([
                        'user_access_token' => $refreshed['access_token'],
                        'token_expires_at' => now()->addSeconds($refreshed['expires_in']),
                    ]);
                    $this->info("  refreshed token: tenant_id={$connection->tenant_id}");
                } catch (FacebookGraphException $e) {
                    Log::error('facebook:refresh-connections: token refresh failed.', [
                        'tenant_id' => $connection->tenant_id,
                        'error' => $e->errorPayload(),
                    ]);

                    if ($e->isInvalidToken()) {
                        $this->markConnectionPagesNeedsReconnect($connection);
                        $this->warn("  token dead, marked pages needs_reconnect: tenant_id={$connection->tenant_id}");

                        return; // no point re-verifying pages below with a token we know is dead
                    }

                    $this->warn("  refresh failed (non-fatal, will retry next run): tenant_id={$connection->tenant_id}");

                    return;
                } catch (ConnectionException $e) {
                    // Deliberately not logging $e->getMessage() — same
                    // token-in-exception-message caution as every other
                    // Graph-calling site in this codebase.
                    Log::error('facebook:refresh-connections: connection failure during token refresh.', [
                        'tenant_id' => $connection->tenant_id,
                    ]);
                    $this->warn("  network error refreshing token (will retry next run): tenant_id={$connection->tenant_id}");

                    return;
                }
            }
        }

        $this->reverifyPages($connection->fresh(), $fb, $dryRun);
    }

    /**
     * Re-fetches this connection's actual managed Pages from Meta — never
     * trusts the stored page_access_token as still valid — and re-subscribes
     * each currently-active Page, refreshing its stored token from the fresh
     * fetch at the same time. A Page no longer present in a fresh
     * /me/accounts response (removed, unpublished, permission revoked) is
     * flagged needs_reconnect rather than left silently stale.
     */
    protected function reverifyPages(FacebookConnection $connection, FacebookOAuthService $fb, bool $dryRun): void
    {
        $activePages = FacebookPage::withoutGlobalScopes()
            ->where('facebook_connection_id', $connection->id)
            ->where('is_active', 1)
            ->get();

        if ($activePages->isEmpty()) {
            return;
        }

        if ($dryRun) {
            foreach ($activePages as $page) {
                $this->line("[dry-run] would re-verify/re-subscribe: tenant_id={$page->tenant_id} page_id={$page->page_id}");
            }

            return;
        }

        try {
            $managedPages = collect($fb->getManagedPages($connection->user_access_token))->keyBy('id');
        } catch (FacebookGraphException $e) {
            Log::error('facebook:refresh-connections: could not list managed pages.', [
                'tenant_id' => $connection->tenant_id,
                'error' => $e->errorPayload(),
            ]);

            if ($e->isInvalidToken()) {
                $this->markConnectionPagesNeedsReconnect($connection);
                $this->warn("  token dead while listing pages, marked needs_reconnect: tenant_id={$connection->tenant_id}");
            }

            return;
        } catch (ConnectionException $e) {
            Log::error('facebook:refresh-connections: connection failure listing managed pages.', ['tenant_id' => $connection->tenant_id]);

            return; // transient — will retry next scheduled run
        }

        foreach ($activePages as $page) {
            $match = $managedPages->get($page->page_id);

            if (! $match) {
                // No longer manageable by this connection's user at all —
                // re-subscribing would be pointless; surface it instead.
                $page->update(['status' => 'needs_reconnect']);
                $this->warn("  page no longer accessible, marked needs_reconnect: tenant_id={$page->tenant_id} page_id={$page->page_id}");

                continue;
            }

            $page->update(['page_access_token' => $match['access_token']]);

            try {
                $subscribed = $fb->subscribePageToWebhook($page->page_id, $match['access_token']);
            } catch (FacebookGraphException $e) {
                Log::error('facebook:refresh-connections: re-subscribe failed.', [
                    'tenant_id' => $page->tenant_id,
                    'page_id' => $page->page_id,
                    'error' => $e->errorPayload(),
                ]);

                $page->update(['status' => $e->isInvalidToken() ? 'needs_reconnect' : 'subscription_failed']);
                $this->warn("  re-subscribe failed: tenant_id={$page->tenant_id} page_id={$page->page_id}");

                continue;
            } catch (ConnectionException $e) {
                Log::error('facebook:refresh-connections: connection failure during re-subscribe.', [
                    'tenant_id' => $page->tenant_id,
                    'page_id' => $page->page_id,
                ]);

                continue; // transient — leave status as-is, retry next run
            }

            $page->update([
                'status' => $subscribed ? 'active' : 'subscription_failed',
                'subscribed_at' => $subscribed ? now() : $page->subscribed_at,
            ]);

            $this->info(($subscribed ? '  OK   ' : '  FAIL ')."tenant_id={$page->tenant_id} page_id={$page->page_id}");
        }
    }

    protected function markConnectionPagesNeedsReconnect(FacebookConnection $connection): void
    {
        FacebookPage::withoutGlobalScopes()
            ->where('facebook_connection_id', $connection->id)
            ->where('is_active', 1)
            ->update(['status' => 'needs_reconnect']);
    }
}
