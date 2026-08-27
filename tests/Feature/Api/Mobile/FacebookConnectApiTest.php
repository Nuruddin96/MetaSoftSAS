<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\FacebookConnection;
use App\Models\FacebookOauthState;
use App\Models\FacebookPage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\FacebookConnectController — reuses the same
 * FacebookOAuthService as Tenant\FacebookConnectController and ports the
 * most relevant scenarios from tests/Feature/Facebook/PageConnectionTest.php
 * (the proven web coverage) to Sanctum. The actual OAuth code exchange is
 * NOT re-tested here — it still happens at the existing, unchanged central
 * callback route, already covered by tests/Feature/Facebook/
 * OAuthCallbackControllerTest.php.
 */
class FacebookConnectApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasTable('facebook_oauth_states')) {
            Schema::create('facebook_oauth_states', function (Blueprint $table) {
                $table->id();
                $table->string('state', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('purpose', 30)->default('messenger');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('facebook_connections')) {
            Schema::create('facebook_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->unsignedBigInteger('connected_by_user_id');
                $table->string('facebook_user_id', 64);
                $table->text('user_access_token');
                $table->timestamp('token_expires_at')->nullable();
                $table->string('granted_scopes', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('facebook_pages')) {
            Schema::create('facebook_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('facebook_connection_id');
                $table->string('page_id', 50)->unique();
                $table->string('page_name', 150)->nullable();
                $table->text('page_access_token')->nullable();
                $table->string('status', 30)->default('active');
                $table->boolean('is_active')->default(true);
                $table->timestamp('subscribed_at')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('messenger_messages')) {
            Schema::create('messenger_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('facebook_page_id')->nullable();
                $table->string('sender_psid', 100);
                $table->string('mid', 100)->nullable()->unique();
                $table->string('direction', 10)->default('in');
                $table->string('status', 20)->default('new');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    protected function connectTenant(int $tenantId, int $userId): FacebookConnection
    {
        return FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'connected_by_user_id' => $userId,
            'facebook_user_id' => 'fbu-'.$tenantId,
            'user_access_token' => 'user-token-'.$tenantId,
        ]);
    }

    public function test_connect_url_mints_a_state_bound_to_the_authenticated_tenant_and_user(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/facebook/connect-url')->assertOk();
        $url = $response->json('authorization_url');

        $this->assertStringStartsWith('https://www.facebook.com/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $state = FacebookOauthState::where('state', $query['state'])->first();
        $this->assertNotNull($state);
        $this->assertSame($tenant->id, $state->tenant_id);
        $this->assertSame($user->id, $state->user_id);
        $this->assertSame('messenger_mobile', $state->purpose);
    }

    public function test_status_reflects_no_connection_when_none_exists(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/facebook/status')->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('connected', false)
            ->assertJsonPath('pages', []);
    }

    public function test_status_reflects_a_connected_active_page_without_exposing_a_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $conn = $this->connectTenant($tenant->id, $user->id);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'page-1', 'page_name' => 'My Shop', 'page_access_token' => 'secret-page-token',
            'status' => 'active', 'is_active' => 1,
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/facebook/status')->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('pages.0.page_id', 'page-1')
            ->assertJsonPath('pages.0.status', 'active');

        $this->assertStringNotContainsString('secret-page-token', $response->getContent());
    }

    public function test_pages_lists_managed_pages_and_marks_already_connected_ones(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $conn = $this->connectTenant($tenant->id, $user->id);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'already-connected', 'page_access_token' => 'tok', 'status' => 'active', 'is_active' => 1,
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['*/me/accounts*' => Http::response(['data' => [
            ['id' => 'already-connected', 'name' => 'Connected Page', 'access_token' => 'secret-1'],
            ['id' => 'not-yet-connected', 'name' => 'New Page', 'access_token' => 'secret-2'],
        ]])]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/facebook/pages')->assertOk();
        $response->assertJsonPath('data.0.connected', true)
            ->assertJsonPath('data.1.connected', false);

        $this->assertStringNotContainsString('secret-1', $response->getContent());
        $this->assertStringNotContainsString('secret-2', $response->getContent());
    }

    public function test_pages_marks_needs_reconnect_on_an_invalid_token_response(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $conn = $this->connectTenant($tenant->id, $user->id);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'old-page', 'page_access_token' => 'tok', 'status' => 'active', 'is_active' => 1,
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['*/me/accounts*' => Http::response([
            'error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190],
        ], 400)]);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/facebook/pages')->assertStatus(422);
        $this->assertSame('needs_reconnect', $page->fresh()->status);
    }

    public function test_connect_verifies_against_a_fresh_graph_call_and_subscribes(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant->id, $user->id);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'new-page', 'name' => 'My Shop Page', 'access_token' => 'fresh-page-token'],
            ]]),
            '*/new-page/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/settings/facebook/pages/new-page/connect')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page.status', 'active');

        $this->assertStringNotContainsString('fresh-page-token', $response->getContent());

        app()->instance('currentTenant', $tenant);
        $page = FacebookPage::where('page_id', 'new-page')->first();
        $this->assertNotNull($page);
        $this->assertSame('fresh-page-token', $page->page_access_token);
        app()->forgetInstance('currentTenant');
    }

    public function test_connect_rejects_a_page_already_claimed_by_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        app()->instance('currentTenant', $tenantA);
        $connA = $this->connectTenant($tenantA->id, $userA->id);
        FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'facebook_connection_id' => $connA->id,
            'page_id' => 'claimed-page', 'page_access_token' => 'tok', 'status' => 'active',
        ]);
        app()->forgetInstance('currentTenant');

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->connectTenant($tenantB->id, $userB->id);

        Http::fake(['*/me/accounts*' => Http::response(['data' => [
            ['id' => 'claimed-page', 'name' => 'Someone Else Page', 'access_token' => 'page-tok'],
        ]])]);

        Sanctum::actingAs($userB);

        $this->postJson('/api/mobile/v1/settings/facebook/pages/claimed-page/connect')->assertStatus(422);

        $this->assertSame(
            1,
            FacebookPage::withoutGlobalScopes()->where('page_id', 'claimed-page')->count(),
        );
        $this->assertSame(
            $tenantA->id,
            FacebookPage::withoutGlobalScopes()->where('page_id', 'claimed-page')->first()->tenant_id,
        );
    }

    public function test_connect_records_subscription_failure_without_blocking_the_connection(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant->id, $user->id);

        Http::fake([
            '*/me/accounts*' => Http::response(['data' => [
                ['id' => 'flaky-page', 'name' => 'Flaky Page', 'access_token' => 'tok'],
            ]]),
            '*/flaky-page/subscribed_apps*' => Http::response(['error' => ['message' => 'temporary failure', 'code' => 1]], 500),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/facebook/pages/flaky-page/connect')->assertOk()
            ->assertJsonPath('ok', false);

        app()->instance('currentTenant', $tenant);
        $page = FacebookPage::where('page_id', 'flaky-page')->first();
        app()->forgetInstance('currentTenant');
        $this->assertSame('subscription_failed', $page->status);
        $this->assertNull($page->subscribed_at);
    }

    public function test_disconnect_enforces_tenant_ownership(): void
    {
        $tenantA = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        $connA = $this->connectTenant($tenantA->id, $this->makeUser($tenantA->id)->id);
        $pageA = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id, 'facebook_connection_id' => $connA->id,
            'page_id' => 'page-a', 'page_access_token' => 'tok-a', 'status' => 'active', 'is_active' => 1,
        ]);
        app()->forgetInstance('currentTenant');

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        Sanctum::actingAs($userB);

        $this->postJson('/api/mobile/v1/settings/facebook/pages/'.$pageA->id.'/disconnect')->assertNotFound();

        $this->assertTrue($pageA->fresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_disconnect_marks_page_inactive_and_clears_the_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $conn = $this->connectTenant($tenant->id, $user->id);
        $page = FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'facebook_connection_id' => $conn->id,
            'page_id' => 'to-disconnect', 'page_access_token' => 'page-token-xyz', 'status' => 'active', 'is_active' => 1,
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['*/subscribed_apps*' => Http::response(['success' => true])]);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/facebook/pages/'.$page->id.'/disconnect')->assertOk()
            ->assertJsonPath('ok', true);

        $page->refresh();
        $this->assertFalse($page->is_active);
        $this->assertNull($page->page_access_token);
        $this->assertNotNull($page->disconnected_at);
    }

    public function test_connection_failure_while_listing_pages_fails_gracefully(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant->id, $user->id);

        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: graph.facebook.com');
        });

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/facebook/pages')->assertStatus(422);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/facebook/connect-url')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/settings/facebook/status')->assertUnauthorized();
        $this->getJson('/api/mobile/v1/settings/facebook/pages')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/facebook/pages/x/connect')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/facebook/pages/1/disconnect')->assertUnauthorized();
    }
}
