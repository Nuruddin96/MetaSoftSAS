<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\MarketingSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\SettingController::marketing()/updateMarketing()/
 * testMarketingCapi() — mirrors Tenant\SettingController::marketing()/
 * testCapiConnection() exactly, see those methods' docblocks for why only
 * 5 of the 9 web-validated fields are mirrored (the other 4 are dead —
 * validated/persisted on Web but consumed nowhere).
 */
class MarketingSettingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // Not part of InteractsWithApiSchema — matches the real schema.sql
        // + chunk48.sql column set (confirmed present in production).
        if (! Schema::hasTable('marketing_settings')) {
            Schema::create('marketing_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('fb_pixel_id', 50)->nullable();
                $table->text('fb_capi_token')->nullable();
                $table->string('fb_test_event_code', 50)->nullable();
                $table->string('gtm_container_id', 20)->nullable();
                $table->string('meta_app_id', 50)->nullable();
                $table->text('meta_app_secret')->nullable();
                $table->text('meta_access_token')->nullable();
                $table->string('meta_ad_account_id', 50)->nullable();
                $table->boolean('capi_test_mode')->default(false);
                $table->string('capi_last_status', 20)->nullable();
                $table->smallInteger('capi_last_http_status')->nullable();
                $table->string('capi_last_error', 255)->nullable();
                $table->timestamp('capi_last_event_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function test_index_returns_defaults_when_unconfigured(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/marketing')->assertOk()
            ->assertJsonPath('fb_pixel_id', null)
            ->assertJsonPath('fb_capi_token_saved', false)
            ->assertJsonPath('capi_test_mode', false)
            ->assertJsonPath('capi_last_status', null);
    }

    public function test_index_never_returns_the_raw_capi_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => '1234567890',
            'fb_capi_token' => 'super-secret-capi-token',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/marketing')->assertOk()
            ->assertJsonPath('fb_pixel_id', '1234567890')
            ->assertJsonPath('fb_capi_token_saved', true);

        $this->assertStringNotContainsString('super-secret-capi-token', $response->getContent());
    }

    public function test_index_exposes_read_only_capi_status_telemetry(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => '123',
            'fb_capi_token' => 'tok',
            'capi_last_status' => 'success',
            'capi_last_http_status' => 200,
            'capi_last_event_at' => now(),
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/marketing')->assertOk()
            ->assertJsonPath('capi_last_status', 'success')
            ->assertJsonPath('capi_last_http_status', 200)
            ->assertJsonPath('capi_last_error', null);
    }

    public function test_update_saves_the_five_real_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/marketing', [
            'fb_pixel_id' => '999888777',
            'fb_capi_token' => 'capi-token-1',
            'fb_test_event_code' => 'TEST123',
            'gtm_container_id' => 'GTM-ABCDEF',
            'capi_test_mode' => true,
        ])->assertOk()->assertJsonPath('ok', true);

        app()->instance('currentTenant', $tenant);
        $setting = MarketingSetting::first();
        $this->assertSame('999888777', $setting->fb_pixel_id);
        $this->assertSame('capi-token-1', $setting->fb_capi_token);
        $this->assertSame('TEST123', $setting->fb_test_event_code);
        $this->assertSame('GTM-ABCDEF', $setting->gtm_container_id);
        $this->assertTrue($setting->capi_test_mode);
    }

    public function test_update_never_persists_the_four_dead_fields(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/marketing', [
            'fb_pixel_id' => '111',
            'meta_app_id' => 'should-be-ignored',
            'meta_app_secret' => 'should-be-ignored',
            'meta_access_token' => 'should-be-ignored',
            'meta_ad_account_id' => 'should-be-ignored',
        ])->assertOk();

        app()->instance('currentTenant', $tenant);
        $setting = MarketingSetting::first();
        $this->assertNull($setting->meta_app_id);
        $this->assertNull($setting->meta_app_secret);
        $this->assertNull($setting->meta_access_token);
        $this->assertNull($setting->meta_ad_account_id);
    }

    public function test_blank_capi_token_preserves_the_existing_saved_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => '111',
            'fb_capi_token' => 'original-secret-token',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/marketing', [
            'fb_pixel_id' => '111',
            'fb_capi_token' => '',
        ])->assertOk();

        app()->instance('currentTenant', $tenant);
        $this->assertSame('original-secret-token', MarketingSetting::first()->fb_capi_token);
    }

    public function test_blank_non_secret_fields_clear_their_saved_value(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        MarketingSetting::create([
            'tenant_id' => $tenant->id,
            'fb_pixel_id' => '111',
            'gtm_container_id' => 'GTM-OLD',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/marketing', ['fb_pixel_id' => '111'])->assertOk();

        app()->instance('currentTenant', $tenant);
        $this->assertNull(MarketingSetting::first()->gtm_container_id);
    }

    public function test_update_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        Sanctum::actingAs($userA);
        $this->postJson('/api/mobile/v1/settings/marketing', ['fb_pixel_id' => 'a-pixel'])->assertOk();

        app()->instance('currentTenant', $tenantB);
        $this->assertNull(MarketingSetting::first());
    }

    public function test_test_capi_connection_requires_pixel_and_token_first(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/marketing/test-capi')->assertOk()
            ->assertJsonPath('success', false);
    }

    public function test_test_capi_connection_calls_the_real_service_when_configured(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        MarketingSetting::create(['tenant_id' => $tenant->id, 'fb_pixel_id' => '123', 'fb_capi_token' => 'tok']);
        app()->forgetInstance('currentTenant');

        Http::fake(['*/events*' => Http::response(['events_received' => 1])]);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/marketing/test-capi')->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/marketing')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/marketing', [])->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/marketing/test-capi')->assertUnauthorized();
    }
}
