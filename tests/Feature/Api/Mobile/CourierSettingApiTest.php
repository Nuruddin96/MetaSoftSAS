<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\CourierSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\SettingController::courier()/updateCourier() — mirrors
 * Tenant\SettingController::courier() exactly, see that controller's
 * docblock.
 */
class CourierSettingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // Not part of InteractsWithApiSchema — mirrors the same stub used
        // by InteractsWithCommerceSchema/InteractsWithWhatsAppSchema/etc.
        if (! Schema::hasTable('courier_settings')) {
            Schema::create('courier_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('provider', 30);
                $table->text('credentials')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                $table->unique(['tenant_id', 'provider']);
            });
        }
    }

    public function test_index_returns_unconfigured_state_for_both_providers(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/courier')->assertOk()
            ->assertJsonPath('steadfast.is_active', false)
            ->assertJsonPath('steadfast.fields.api_key', false)
            ->assertJsonPath('steadfast.fields.secret_key', false)
            ->assertJsonPath('pathao.is_active', false)
            ->assertJsonPath('pathao.fields.client_id', false)
            ->assertJsonPath('pathao.fields.store_id', false);
    }

    public function test_index_never_returns_raw_credential_values(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        CourierSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'super-secret-key', 'secret_key' => 'super-secret-value'],
            'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/settings/courier')->assertOk();
        $response->assertJsonPath('steadfast.is_active', true)
            ->assertJsonPath('steadfast.fields.api_key', true)
            ->assertJsonPath('steadfast.fields.secret_key', true);

        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_update_saves_steadfast_configuration(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/courier', [
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'key-1', 'secret_key' => 'secret-1'],
            'is_active' => true,
        ])->assertOk()->assertJsonPath('ok', true);

        app()->instance('currentTenant', $tenant);
        $setting = CourierSetting::where('provider', 'steadfast')->first();
        $this->assertSame('key-1', $setting->credentials['api_key']);
        $this->assertSame('secret-1', $setting->credentials['secret_key']);
        $this->assertTrue($setting->is_active);
    }

    public function test_update_saves_pathao_configuration(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/courier', [
            'provider' => 'pathao',
            'credentials' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'username' => 'merchant@example.com',
                'password' => 'pw',
                'store_id' => 'store-9',
            ],
            'is_active' => false,
        ])->assertOk();

        app()->instance('currentTenant', $tenant);
        $setting = CourierSetting::where('provider', 'pathao')->first();
        $this->assertSame('cid', $setting->credentials['client_id']);
        $this->assertSame('store-9', $setting->credentials['store_id']);
        $this->assertFalse($setting->is_active);
    }

    public function test_update_rejects_an_unknown_provider(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/courier', [
            'provider' => 'redx',
            'credentials' => ['api_key' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrors('provider');
    }

    public function test_blank_credential_field_preserves_the_existing_saved_secret(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        CourierSetting::create([
            'tenant_id' => $tenant->id,
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'original-key', 'secret_key' => 'original-secret'],
            'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/courier', [
            'provider' => 'steadfast',
            'credentials' => ['api_key' => '', 'secret_key' => ''],
            'is_active' => true,
        ])->assertOk();

        app()->instance('currentTenant', $tenant);
        $setting = CourierSetting::where('provider', 'steadfast')->first();
        $this->assertSame('original-key', $setting->credentials['api_key']);
        $this->assertSame('original-secret', $setting->credentials['secret_key']);
    }

    public function test_update_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        Sanctum::actingAs($userA);
        $this->postJson('/api/mobile/v1/settings/courier', [
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'a-key', 'secret_key' => 'a-secret'],
            'is_active' => true,
        ])->assertOk();

        app()->instance('currentTenant', $tenantB);
        $this->assertNull(CourierSetting::where('provider', 'steadfast')->first());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/courier')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/courier', ['provider' => 'steadfast', 'credentials' => []])
            ->assertUnauthorized();
    }
}
