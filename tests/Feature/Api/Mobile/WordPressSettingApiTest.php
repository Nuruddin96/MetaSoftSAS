<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Plan;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressConnectorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\SettingController::wordpress()/generateWordPressKey()/
 * verifyWordPress()/disconnectWordPress() — mirrors
 * Tenant\WordPressConnectController exactly, see that controller's and
 * SettingController's docblocks. Scenarios ported from the web-side
 * tests/Feature/WordPress/WordPressConnectTest.php, adapted for Sanctum/JSON.
 */
class WordPressSettingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // InteractsWithCommerceSchema's hand-built `plans` table predates
        // the `features` JSON column (only Facebook/WhatsApp/WordPress
        // feature-gating tests need it) — added here rather than editing
        // that shared trait, same reasoning InteractsWithApiSchema itself
        // already uses for its own `orders`/`tenants` column additions.
        if (! Schema::hasColumn('plans', 'features')) {
            Schema::table('plans', fn (Blueprint $table) => $table->json('features')->nullable());
        }

        if (! Schema::hasTable('wordpress_connections')) {
            Schema::create('wordpress_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->unsignedBigInteger('connected_by_user_id');
                $table->string('site_url', 255);
                $table->string('site_name', 150)->nullable();
                $table->string('wp_rest_url', 255)->nullable();
                $table->string('plugin_version', 20)->nullable();
                $table->string('wp_version', 20)->nullable();
                $table->boolean('woocommerce_active')->default(false);
                $table->string('woocommerce_version', 20)->nullable();
                $table->text('outbound_secret')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_verified_at')->nullable();
                $table->timestamp('disconnected_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wordpress_connection_tokens')) {
            Schema::create('wordpress_connection_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    private function wordpressEnabledPlan(): Plan
    {
        return Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'features' => ['wordpress_connect'],
        ]);
    }

    private function connectTenant($tenant, $user): WordPressConnection
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

    // --- status --------------------------------------------------------------

    public function test_status_returns_null_connection_when_nothing_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/wordpress')->assertOk()
            ->assertJsonPath('not_ready', false)
            ->assertJsonPath('connection', null);
    }

    public function test_status_returns_masked_connection_details(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->connectTenant($tenant, $user);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/wordpress')->assertOk()
            ->assertJsonPath('connection.site_url', 'https://example-shop.com')
            ->assertJsonPath('connection.status', 'connected')
            ->assertJsonPath('connection.woocommerce_active', true);

        $this->assertStringNotContainsString('outbound_secret', $response->getContent());
    }

    public function test_status_degrades_gracefully_when_tables_not_imported(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        Schema::dropIfExists('wordpress_connections');
        Schema::dropIfExists('wordpress_connection_tokens');

        $this->getJson('/api/mobile/v1/settings/wordpress')->assertOk()
            ->assertJsonPath('not_ready', true);
    }

    // --- generate key ----------------------------------------------------------

    public function test_tenant_can_generate_a_connection_key(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->wordpressEnabledPlan()->id]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/settings/wordpress/generate-key')->assertOk();
        $this->assertSame(64, strlen($response->json('connection_key')));
        $this->assertNotNull($response->json('expires_at'));
    }

    public function test_generate_key_is_gated_behind_the_wordpress_connect_feature(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/wordpress/generate-key')
            ->assertStatus(403);
    }

    // --- verify ------------------------------------------------------------------

    public function test_verify_marks_connection_healthy_when_plugin_health_check_succeeds(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $connection = $this->connectTenant($tenant, $user);
        app()->forgetInstance('currentTenant');

        Http::fake([
            'example-shop.com/wp-json/metasoft/v1/health' => Http::response(['connected' => true], 200),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/v1/settings/wordpress/verify')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'connected');

        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_verify_flags_needs_reconnect_when_plugin_unreachable(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $connection = $this->connectTenant($tenant, $user);
        app()->forgetInstance('currentTenant');

        Http::fake(['example-shop.com/*' => Http::response(null, 500)]);

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/v1/settings/wordpress/verify')->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'needs_reconnect');

        $this->assertSame('needs_reconnect', $connection->fresh()->status);
    }

    public function test_verify_returns_404_when_no_connection_exists(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/wordpress/verify')->assertStatus(404);
    }

    // --- disconnect -----------------------------------------------------------

    public function test_disconnect_clears_credentials_and_revokes_tokens(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $connection = $this->connectTenant($tenant, $user);
        $this->assertSame(1, $connection->tokens()->count());
        app()->forgetInstance('currentTenant');

        Http::fake(['example-shop.com/*' => Http::response(['disconnected' => true], 200)]);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/mobile/v1/settings/wordpress')->assertOk()->assertJsonPath('ok', true);

        $connection->refresh();
        $this->assertSame('disconnected', $connection->status);
        $this->assertNull($connection->outbound_secret);
        $this->assertSame(0, $connection->tokens()->count());
    }

    // --- tenant isolation ----------------------------------------------------

    public function test_a_tenant_cannot_see_another_tenants_wordpress_connection(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        app()->instance('currentTenant', $tenantA);
        $this->connectTenant($tenantA, $userA);
        app()->forgetInstance('currentTenant');

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        Sanctum::actingAs($userB);

        $this->getJson('/api/mobile/v1/settings/wordpress')->assertOk()
            ->assertJsonPath('connection', null);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/wordpress')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/wordpress/generate-key')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/wordpress/verify')->assertUnauthorized();
        $this->deleteJson('/api/mobile/v1/settings/wordpress')->assertUnauthorized();
    }
}
