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

    // --- Multi-Page / same-Facebook-account / cross-tenant lifecycle (bug fix regression suite) ----

    /**
     * The reported bug, reproduced and fixed: the SAME Facebook account
     * (same FacebookConnection-worth of Pages returned by /me/accounts)
     * connects Page A to Tenant A, then a completely different Page B to
     * Tenant B — both must succeed and coexist, neither displaces the other.
     */
    public function test_same_facebook_account_connects_different_pages_to_different_tenants(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'facebook_user_id' => 'shared-fb-user', 'user_access_token' => 'token-a',
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'facebook_user_id' => 'shared-fb-user', 'user_access_token' => 'token-b',
        ]);

        // Same Facebook account manages both Pages — this is exactly what
        // /me/accounts legitimately returns for one person managing two Pages.
        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'page-a', 'name' => 'Page A', 'access_token' => 'page-a-tok'],
                ['id' => 'page-b', 'name' => 'Page B', 'access_token' => 'page-b-tok'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($userA, 'tenant')
            ->post($this->panelUrl($tenantA, 'facebook/pages/page-a/connect'))
            ->assertRedirect();

        $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'facebook/pages/page-b/connect'))
            ->assertRedirect();

        $this->assertSame($tenantA->id, FacebookPage::withoutGlobalScopes()->where('page_id', 'page-a')->value('tenant_id'));
        $this->assertSame($tenantB->id, FacebookPage::withoutGlobalScopes()->where('page_id', 'page-b')->value('tenant_id'));
        $this->assertTrue((bool) FacebookPage::withoutGlobalScopes()->where('page_id', 'page-a')->value('is_active'));
        $this->assertTrue((bool) FacebookPage::withoutGlobalScopes()->where('page_id', 'page-b')->value('is_active'));
    }

    /** The core bug: disconnecting a Page must release it, not leave it permanently unclaimable. */
    public function test_disconnected_page_can_be_connected_by_a_different_tenant(): void
    {
        // Both tenants/users created up front — User::find() (BelongsToTenant)
        // is scoped by app('currentTenant'), which the resolve.tenant
        // middleware rebinds for the whole rest of the process the instant
        // the first actingAs()->post() below runs; creating Tenant B's user
        // only after that request would make makeUser() invisible to its own
        // find() call under the now-stuck Tenant A scope.
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $connA = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $userA->id,
            'facebook_user_id' => 'fbu-a', 'user_access_token' => 'token-a',
        ]);
        $pageA = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'facebook_connection_id' => $connA->id,
            'page_id' => 'reusable-page', 'page_access_token' => 'tok-a', 'status' => 'active', 'is_active' => 1,
        ]);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'facebook_user_id' => 'fbu-b', 'user_access_token' => 'token-b',
        ]);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        // Tenant A disconnects.
        $this->actingAs($userA, 'tenant')
            ->post($this->panelUrl($tenantA, 'facebook/pages/'.$pageA->id.'/disconnect'))
            ->assertRedirect();

        $this->assertFalse((bool) $pageA->fresh()->is_active);

        // Tenant B, a different Facebook-connected tenant, now claims the same page_id.
        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'reusable-page', 'name' => 'Reusable Page', 'access_token' => 'fresh-tok-b'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'facebook/pages/reusable-page/connect'))
            ->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenantB->subdomain]));

        // Same row (id preserved — reassigned, not duplicated: page_id is UNIQUE).
        $page = FacebookPage::withoutGlobalScopes()->where('page_id', 'reusable-page')->first();
        $this->assertSame($pageA->id, $page->id);
        $this->assertSame($tenantB->id, $page->tenant_id);
        $this->assertTrue((bool) $page->is_active);
        $this->assertSame('fresh-tok-b', $page->page_access_token);
    }

    /** A Page still ACTIVE under another tenant must still be rejected — the duplicate-page protection itself must not weaken. */
    public function test_still_active_page_under_another_tenant_is_still_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $connA = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'connected_by_user_id' => $this->makeUser($tenantA->id)->id,
            'facebook_user_id' => 'fbu-a', 'user_access_token' => 'token-a',
        ]);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'facebook_connection_id' => $connA->id,
            'page_id' => 'still-active-page', 'page_access_token' => 'tok-a', 'status' => 'active', 'is_active' => 1,
        ]);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id, 'connected_by_user_id' => $userB->id,
            'facebook_user_id' => 'fbu-b', 'user_access_token' => 'token-b',
        ]);

        Http::fake(['*/me/accounts*' => Http::response(['data' => [
            ['id' => 'still-active-page', 'name' => 'Still Active', 'access_token' => 'tok'],
        ]])]);

        $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'facebook/pages/still-active-page/connect'))
            ->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenantB->subdomain]));

        $this->assertSame($tenantA->id, FacebookPage::withoutGlobalScopes()->where('page_id', 'still-active-page')->value('tenant_id'));
    }

    /** A tenant reconnecting the exact same Page it just disconnected must keep working (self-reconnect, not just cross-tenant reassignment). */
    public function test_same_tenant_can_reconnect_its_own_disconnected_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'token',
        ]);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'my-own-page', 'page_access_token' => 'tok', 'status' => 'active', 'is_active' => 1,
        ]);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/'.$page->id.'/disconnect'))
            ->assertRedirect();

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'my-own-page', 'name' => 'My Own Page', 'access_token' => 'tok-2'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'facebook/pages/my-own-page/connect'))
            ->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));

        $page->refresh();
        $this->assertTrue((bool) $page->is_active);
        $this->assertSame($tenant->id, $page->tenant_id);
    }

    /**
     * Tenant deletion: production's schema (database/sql/chunk23.sql)
     * defines facebook_pages.tenant_id / facebook_connections.tenant_id as
     * FOREIGN KEY ... ON DELETE CASCADE — a real DELETE FROM tenants (this
     * app never soft-deletes tenants, see SuperAdmin\TenantController::
     * destroy()) already removes both rows at the database level (verified
     * against production: zero orphaned facebook_pages/facebook_connections
     * rows). The sqlite test schema here (InteractsWithFacebookSchema)
     * doesn't declare real foreign keys, so this test simulates the
     * post-cascade state directly and proves the APPLICATION-level ownership
     * check — the thing that was actually buggy — correctly frees the page.
     */
    public function test_page_from_a_permanently_deleted_tenant_can_be_claimed_by_another_tenant(): void
    {
        $deletedTenant = $this->makeTenant();
        $deletedConn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $deletedTenant->id, 'connected_by_user_id' => $this->makeUser($deletedTenant->id)->id,
            'facebook_user_id' => 'fbu-gone', 'user_access_token' => 'token-gone',
        ]);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $deletedTenant->id, 'facebook_connection_id' => $deletedConn->id,
            'page_id' => 'orphan-candidate', 'page_access_token' => 'tok', 'status' => 'active', 'is_active' => 1,
        ]);

        // Simulate what production's ON DELETE CASCADE does for a real tenant delete.
        FacebookPage::withoutGlobalScopes()->where('tenant_id', $deletedTenant->id)->delete();
        FacebookConnection::withoutGlobalScopes()->where('tenant_id', $deletedTenant->id)->delete();
        $deletedTenant->delete();

        $newTenant = $this->makeTenant();
        $newUser = $this->makeUser($newTenant->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $newTenant->id, 'connected_by_user_id' => $newUser->id,
            'facebook_user_id' => 'fbu-new', 'user_access_token' => 'token-new',
        ]);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'orphan-candidate', 'name' => 'Freed Page', 'access_token' => 'tok-new'],
            ]]),
            '*/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->actingAs($newUser, 'tenant')
            ->post($this->panelUrl($newTenant, 'facebook/pages/orphan-candidate/connect'))
            ->assertRedirect(route('tenant.settings', ['tenant_slug' => $newTenant->subdomain]));

        $this->assertSame($newTenant->id, FacebookPage::withoutGlobalScopes()->where('page_id', 'orphan-candidate')->value('tenant_id'));
    }

    /**
     * The picker itself (getManagedPages -> pages()) must never filter out
     * or reorder Pages based on any local cache/session/previous selection
     * — every Page Meta actually returns for this Facebook account must be
     * offered, matching investigation item 11 ("does the frontend always
     * select the first/previous Page"). This proves the CONTROLLER always
     * relies on a fresh live Graph call, never a stored/cached Page list.
     */
    public function test_pages_picker_always_lists_every_page_from_a_fresh_graph_call(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'token',
        ]);

        Http::fake(['*/me/accounts*' => Http::response(['data' => [
            ['id' => 'p1', 'name' => 'Page One', 'access_token' => 't1'],
            ['id' => 'p2', 'name' => 'Page Two', 'access_token' => 't2'],
            ['id' => 'p3', 'name' => 'Page Three', 'access_token' => 't3'],
        ]])]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'facebook/pages'));

        $response->assertOk();
        $response->assertViewHas('pages', function ($pages) {
            return collect($pages)->pluck('id')->all() === ['p1', 'p2', 'p3'];
        });
        Http::assertSentCount(1); // one live call, nothing served from cache
    }
}
