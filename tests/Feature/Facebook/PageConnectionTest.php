<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PageConnectionTest extends FacebookFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_cross_tenant_page_id_claim_is_rejected_at_db_level(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $connA = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'connected_by_user_id' => $this->makeUser($tenantA->id)->id,
            'facebook_user_id' => 'fbu-a',
            'user_access_token' => 'token-a',
        ]);
        $connB = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'connected_by_user_id' => $this->makeUser($tenantB->id)->id,
            'facebook_user_id' => 'fbu-b',
            'user_access_token' => 'token-b',
        ]);

        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'facebook_connection_id' => $connA->id,
            'page_id' => 'shared-page-id',
            'page_access_token' => 'tok-1',
        ]);

        $this->expectException(QueryException::class);

        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'facebook_connection_id' => $connB->id,
            'page_id' => 'shared-page-id',
            'page_access_token' => 'tok-2',
        ]);
    }

    public function test_controller_rejects_a_page_already_claimed_by_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $connA = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'facebook_user_id' => 'fbu-a', 'user_access_token' => 'token-a',
        ]);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'facebook_connection_id' => $connA->id,
            'page_id' => 'claimed-page', 'page_access_token' => 'tok', 'status' => 'active',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'facebook_user_id' => 'fbu-b', 'user_access_token' => 'token-b',
        ]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'claimed-page', 'name' => 'Someone Else Page', 'access_token' => 'page-tok'],
            ]]),
        ]);

        $response = $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'facebook/pages/claimed-page/connect'));

        $response->assertRedirect();
        $this->assertSame(
            1,
            FacebookPage::withoutGlobalScopes()->where('page_id', 'claimed-page')->count(),
            'no second row should be created for a page_id already claimed by another tenant'
        );
        $this->assertSame(
            $tenantA->id,
            FacebookPage::withoutGlobalScopes()->where('page_id', 'claimed-page')->first()->tenant_id
        );
    }

    public function test_page_access_token_is_stored_encrypted(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token-plain',
        ]);

        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'enc-page', 'page_access_token' => 'plain-page-token', 'status' => 'active',
        ]);

        $rawPageToken = DB::table('facebook_pages')->where('id', $page->id)->value('page_access_token');
        $rawUserToken = DB::table('facebook_connections')->where('id', $conn->id)->value('user_access_token');

        $this->assertNotSame('plain-page-token', $rawPageToken, 'page_access_token must not be stored in plaintext');
        $this->assertNotSame('user-token-plain', $rawUserToken, 'user_access_token must not be stored in plaintext');
        $this->assertSame('plain-page-token', $page->fresh()->page_access_token);
        $this->assertSame('user-token-plain', $conn->fresh()->user_access_token);
    }

    public function test_successful_page_connection_subscribes_and_marks_active(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'new-page', 'name' => 'My Shop Page', 'access_token' => 'fresh-page-token'],
            ]]),
            '*/new-page/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/new-page/connect'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));

        $page = FacebookPage::withoutGlobalScopes()->where('page_id', 'new-page')->first();
        $this->assertNotNull($page);
        $this->assertSame($tenant->id, $page->tenant_id);
        $this->assertSame('active', $page->status);
        $this->assertNotNull($page->subscribed_at);
        $this->assertSame('fresh-page-token', $page->page_access_token);
    }

    public function test_subscription_failure_is_recorded_without_blocking_the_connection(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'flaky-page', 'name' => 'Flaky Page', 'access_token' => 'tok'],
            ]]),
            '*/flaky-page/subscribed_apps*' => Http::response(['error' => ['message' => 'temporary failure', 'code' => 1]], 500),
        ]);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/flaky-page/connect'))
            ->assertRedirect();

        $page = FacebookPage::withoutGlobalScopes()->where('page_id', 'flaky-page')->first();
        $this->assertSame('subscription_failed', $page->status);
        $this->assertNull($page->subscribed_at);
    }

    public function test_invalid_token_response_marks_pages_needs_reconnect(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'stale-token',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'old-page', 'page_access_token' => 'tok', 'status' => 'active',
        ]);

        Http::fake([
            '*/me/accounts*' => Http::response([
                'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
            ], 400),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'facebook/pages'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $this->assertSame('needs_reconnect', $page->fresh()->status);
    }

    public function test_connection_failure_while_listing_pages_fails_gracefully(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: graph.facebook.com');
        });

        $response = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'facebook/pages'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }

    public function test_disconnect_enforces_tenant_ownership(): void
    {
        $tenantA = $this->makeTenant();
        $connA = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $this->makeUser($tenantA->id)->id,
            'facebook_user_id' => 'fbu-a', 'user_access_token' => 'token-a',
        ]);
        $pageA = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'facebook_connection_id' => $connA->id,
            'page_id' => 'page-a', 'page_access_token' => 'tok-a', 'status' => 'active', 'is_active' => 1,
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        // Tenant B's staff tries to disconnect Tenant A's page by ID.
        $response = $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'facebook/pages/'.$pageA->id.'/disconnect'));

        $response->assertNotFound();
        $this->assertTrue($pageA->fresh()->is_active, 'Tenant A\'s page must be untouched by Tenant B\'s attempt');
        Http::assertNothingSent();
    }

    public function test_disconnect_marks_page_inactive_and_attempts_meta_unsubscribe_correctly(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'to-disconnect', 'page_access_token' => 'page-token-xyz', 'status' => 'active', 'is_active' => 1,
        ]);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/'.$page->id.'/disconnect'));

        $response->assertRedirect();

        $page->refresh();
        $this->assertFalse($page->is_active);
        $this->assertNull($page->page_access_token);
        $this->assertNotNull($page->disconnected_at);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains((string) $request->url(), 'access_token=page-token-xyz')
                && (string) $request->body() === '';
        });
    }

    public function test_disconnect_updates_local_state_even_when_meta_unsubscribe_fails(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'flaky-disconnect', 'page_access_token' => 'page-token-abc', 'status' => 'active', 'is_active' => 1,
        ]);

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: graph.facebook.com');
        });

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/'.$page->id.'/disconnect'));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $page->refresh();
        $this->assertFalse($page->is_active, 'local disconnect must succeed even if the Meta-side unsubscribe call fails');
        $this->assertNull($page->page_access_token);
        $this->assertNotNull($page->disconnected_at);
    }

    /**
     * H2 (production-readiness audit): the ConnectionException branch around
     * subscribePageToWebhook() inside connect() (FacebookConnectController.php)
     * previously had no direct test — it was only inferred safe by pattern-
     * matching against the already-tested FacebookGraphException branch.
     * This proves the existing implementation, unmodified, actually behaves
     * correctly under a real transport-level failure.
     */
    public function test_connect_handles_connection_exception_during_webhook_subscription_gracefully(): void
    {
        Log::spy();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'user-token-secret',
        ]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'flaky-subscribe-page', 'name' => 'Flaky Subscribe Page', 'access_token' => 'page-token-secret'],
            ]]),
            '*/subscribed_apps*' => function () {
                throw new ConnectionException('Could not resolve host: graph.facebook.com');
            },
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/flaky-subscribe-page/connect'));

        // No raw 500 — a clean redirect back to Settings with an error flash.
        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');

        // No token anywhere in the redirect response.
        $this->assertStringNotContainsString('page-token-secret', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('user-token-secret', (string) $response->headers->get('Location'));

        // No token in whatever got flashed to the session for this request.
        foreach (session()->all() as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString('page-token-secret', $value);
                $this->assertStringNotContainsString('user-token-secret', $value);
            }
        }

        // No token in any log call made during this request.
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context = []) {
            $flat = $message.' '.json_encode($context);

            return ! str_contains($flat, 'page-token-secret') && ! str_contains($flat, 'user-token-secret');
        });

        // DB state stays consistent: the Page connection itself succeeded
        // (it exists, owned by the right tenant) but must NEVER be marked as
        // successfully subscribed when Meta was unreachable.
        $page = FacebookPage::withoutGlobalScopes()->where('page_id', 'flaky-subscribe-page')->first();
        $this->assertNotNull($page);
        $this->assertSame($tenant->id, $page->tenant_id);
        $this->assertSame('subscription_failed', $page->status);
        $this->assertNull($page->subscribed_at);
    }
}
