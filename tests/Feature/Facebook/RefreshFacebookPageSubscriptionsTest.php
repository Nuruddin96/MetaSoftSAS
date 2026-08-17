<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Regression coverage for the root cause behind "Messenger stops receiving
 * new messages until Facebook is disconnected and reconnected":
 * exchangeForLongLivedToken() / subscribePageToWebhook() were only ever
 * called once, at initial connect — see RefreshFacebookPageSubscriptions's
 * docblock for the full trace. These tests prove the new scheduled command
 * closes that gap, and that normal webhook delivery never depended on a
 * recent reconnect in the first place.
 */
class RefreshFacebookPageSubscriptionsTest extends FacebookFeatureTestCase
{
    protected function makeConnectionAndPage(array $connectionAttrs = [], array $pageAttrs = []): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $connection = FacebookConnection::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu-'.$tenant->id, 'user_access_token' => 'old-token-'.$tenant->id,
            'token_expires_at' => now()->addDays(3), // within the refresh window
        ], $connectionAttrs));

        $page = FacebookPage::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $connection->id,
            'page_id' => 'page-'.$tenant->id, 'page_access_token' => 'old-page-token-'.$tenant->id,
            'status' => 'active', 'is_active' => 1,
        ], $pageAttrs));

        return [$tenant, $connection, $page];
    }

    public function test_token_within_the_refresh_window_gets_refreshed(): void
    {
        [, $connection, $page] = $this->makeConnectionAndPage();

        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'fresh-token', 'expires_in' => 5184000]),
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => $page->page_id, 'name' => 'Page', 'access_token' => 'fresh-page-token'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        $connection->refresh();
        $this->assertSame('fresh-token', $connection->user_access_token);
        $this->assertTrue($connection->token_expires_at->isAfter(now()->addDays(50)));
    }

    public function test_token_not_near_expiry_is_not_refreshed_but_pages_are_still_reverified(): void
    {
        [, $connection, $page] = $this->makeConnectionAndPage(['token_expires_at' => now()->addDays(45)]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => $page->page_id, 'name' => 'Page', 'access_token' => 'refreshed-page-token'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/oauth/access_token'));
        $this->assertSame('old-token-'.$connection->tenant_id, $connection->fresh()->user_access_token);
        $this->assertSame('refreshed-page-token', $page->fresh()->page_access_token, 'pages must still be re-verified/re-subscribed even when the token itself is not near expiry');
    }

    public function test_active_page_is_resubscribed_and_marked_active(): void
    {
        [, , $page] = $this->makeConnectionAndPage(['token_expires_at' => now()->addDays(45)], ['status' => 'needs_reconnect']);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => $page->page_id, 'name' => 'Page', 'access_token' => 'fresh-page-token'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        $page->refresh();
        $this->assertSame('active', $page->status);
        $this->assertNotNull($page->subscribed_at);
    }

    public function test_invalid_token_during_refresh_marks_pages_needs_reconnect(): void
    {
        [, , $page] = $this->makeConnectionAndPage();

        Http::fake(['*/oauth/access_token*' => Http::response([
            'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
        ], 401)]);

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        $this->assertSame('needs_reconnect', $page->fresh()->status);
    }

    public function test_page_no_longer_in_a_fresh_managed_pages_list_is_marked_needs_reconnect(): void
    {
        [, , $page] = $this->makeConnectionAndPage(['token_expires_at' => now()->addDays(45)]);

        // Fresh /me/accounts no longer includes this page at all (removed,
        // unpublished, or the connection's user lost admin access).
        Http::fake(['*/me/accounts*' => Http::response(['data' => []])]);

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        $this->assertSame('needs_reconnect', $page->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/subscribed_apps'));
    }

    public function test_subscription_failure_is_marked_subscription_failed_not_needs_reconnect(): void
    {
        [, , $page] = $this->makeConnectionAndPage(['token_expires_at' => now()->addDays(45)]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => $page->page_id, 'name' => 'Page', 'access_token' => 'tok'],
            ]]),
            '*/subscribed_apps*' => Http::response(['error' => ['message' => 'temporary failure', 'code' => 1]], 500),
        ]);

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        $this->assertSame('subscription_failed', $page->fresh()->status);
    }

    public function test_connection_failure_is_non_fatal(): void
    {
        [, , $page] = $this->makeConnectionAndPage();

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: graph.facebook.com');
        });

        $this->artisan('facebook:refresh-connections')->assertSuccessful();

        // Left untouched — will retry on the next scheduled run, not marked as broken.
        $this->assertSame('active', $page->fresh()->status);
    }

    public function test_dry_run_makes_no_http_calls(): void
    {
        $this->makeConnectionAndPage();
        Http::fake();

        $this->artisan('facebook:refresh-connections', ['--dry-run' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * The regression test your report explicitly asked for: a connected
     * Page receives a new webhook message, tenant is resolved, message is
     * stored, all WITHOUT any reconnect happening immediately beforehand —
     * proving webhook delivery itself never depended on recency of
     * reconnect. subscribed_at is deliberately set far in the past here.
     */
    public function test_a_long_connected_page_still_receives_and_stores_new_messages_without_a_fresh_reconnect(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        [$tenant, , $page] = $this->makeConnectionAndPage([], [
            'subscribed_at' => now()->subDays(45), // connected/subscribed weeks ago, never touched since
        ]);

        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => $page->page_id,
                'messaging' => [[
                    'sender' => ['id' => 'psid-longstanding'],
                    'message' => ['mid' => 'mid-longstanding-1', 'text' => 'still working?'],
                ]],
            ]],
        ]);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        $response = $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        $response->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-longstanding-1')->first();
        $this->assertNotNull($message, 'webhook delivery must not depend on how recently the Page was connected/reconnected');
        $this->assertSame($tenant->id, $message->tenant_id);
    }

    /** Confirms the manual reconnect flow (Phase 1 Facebook OAuth) still works unchanged alongside the new scheduled command. */
    public function test_manual_reconnect_flow_still_works_unchanged(): void
    {
        [$tenant, , $page] = $this->makeConnectionAndPage([], ['status' => 'needs_reconnect']);
        $user = \App\Models\User::where('tenant_id', $tenant->id)->first();

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => $page->page_id, 'name' => 'Page', 'access_token' => 'reconnect-token'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post('/shop/'.$tenant->subdomain.'/panel/facebook/pages/'.$page->page_id.'/connect');

        $response->assertRedirect();
        $this->assertSame('active', $page->fresh()->status);
    }
}
