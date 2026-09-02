<?php

namespace Tests\Feature\WordPress;

use App\Models\Plan;
use App\Models\WordPressConnection;
use App\Models\WordPressConnectionToken;
use App\Services\WordPress\WordPressConnectorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class WordPressConnectTest extends WordPressFeatureTestCase
{
    // --- generate key ------------------------------------------------------

    public function test_tenant_can_generate_a_connection_key(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'wordpress/generate-key'));

        $response->assertRedirect(route('tenant.wordpress.index', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('connection_key');

        $token = WordPressConnectionToken::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token->token));
        $this->assertSame($user->id, $token->user_id);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertNull($token->used_at);
    }

    public function test_generate_key_is_gated_behind_the_wordpress_connect_feature(): void
    {
        $planWithoutFeature = Plan::create(['name' => 'Basic', 'slug' => 'basic-'.uniqid(), 'features' => []]);
        $tenant = $this->makeTenant(['plan_id' => $planWithoutFeature->id]);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'wordpress/generate-key'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
        $this->assertSame(0, WordPressConnectionToken::count());
    }

    public function test_index_degrades_gracefully_when_chunk59_not_imported(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        // Simulate chunk59.sql not imported by dropping the tables this
        // specific test just created via setUpWordPressSchema(true).
        Schema::dropIfExists('wordpress_connections');
        Schema::dropIfExists('wordpress_connection_tokens');

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'wordpress'));

        $response->assertOk();
        $response->assertSee('প্রস্তুত হয়নি');
    }

    // --- handshake completes a pending connection --------------------------

    protected function connectTenant($tenant, $user): WordPressConnection
    {
        $service = new WordPressConnectorService;
        $state = $service->createConnectionToken($tenant, $user);

        [$connection] = $service->completeHandshake($state, [
            'site_url' => 'https://example-shop.com',
            'site_name' => 'Example Shop',
            'wp_rest_url' => 'https://example-shop.com/wp-json',
            'plugin_version' => '0.1.0',
            'wp_version' => '6.5',
            'woocommerce_active' => true,
            'woocommerce_version' => '8.0',
        ]);

        return $connection->fresh();
    }

    // --- verify --------------------------------------------------------------

    public function test_verify_marks_connection_healthy_when_plugin_health_check_succeeds(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $connection = $this->connectTenant($tenant, $user);

        Http::fake([
            'example-shop.com/wp-json/metasoft/v1/health' => Http::response(['connected' => true], 200),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'wordpress/verify'));

        $response->assertRedirect(route('tenant.wordpress.index', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('success');

        $connection->refresh();
        $this->assertSame('connected', $connection->status);
        $this->assertNotNull($connection->last_verified_at);
    }

    public function test_verify_flags_needs_reconnect_when_plugin_unreachable(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $connection = $this->connectTenant($tenant, $user);

        Http::fake([
            'example-shop.com/*' => Http::response(null, 500),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'wordpress/verify'));

        $response->assertSessionHas('error');

        $connection->refresh();
        $this->assertSame('needs_reconnect', $connection->status);
    }

    // --- disconnect ----------------------------------------------------------

    public function test_disconnect_clears_credentials_and_revokes_tokens(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $connection = $this->connectTenant($tenant, $user);

        $this->assertSame(1, $connection->tokens()->count());

        Http::fake([
            'example-shop.com/*' => Http::response(['disconnected' => true], 200),
        ]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'wordpress/disconnect'));

        $response->assertSessionHas('success');

        $connection->refresh();
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->outbound_secret);
        $this->assertSame(0, $connection->tokens()->count());
    }

    // --- tenant isolation ------------------------------------------------------

    public function test_a_tenant_cannot_see_another_tenants_wordpress_connection(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->connectTenant($tenantA, $userA);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $response = $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'wordpress'));

        $response->assertOk();
        $response->assertDontSee('example-shop.com');
        $response->assertSee('কোনো সাইট কানেক্ট করা নেই');
    }
}
