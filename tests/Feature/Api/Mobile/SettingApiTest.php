<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\StoreSetting;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\SettingController — the delivery-charge slice of
 * Tenant\SettingController's real surface, added for Priority 3 parity.
 * See that controller's docblock for why courier/AI-agent/marketing
 * settings aren't included.
 */
class SettingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_index_returns_configured_delivery_charges(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '70']);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_outside_dhaka', 'value' => '150']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings')->assertOk()
            ->assertJsonPath('delivery_charge_inside_dhaka', 70)
            ->assertJsonPath('delivery_charge_outside_dhaka', 150);
    }

    public function test_index_falls_back_to_defaults_when_unconfigured(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings')->assertOk()
            ->assertJsonPath('delivery_charge_inside_dhaka', 60)
            ->assertJsonPath('delivery_charge_outside_dhaka', 120);
    }

    public function test_store_saves_both_charges(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings', [
            'delivery_charge_inside_dhaka' => 80,
            'delivery_charge_outside_dhaka' => 160,
        ])->assertOk()->assertJsonPath('ok', true);

        app()->instance('currentTenant', $tenant);
        $this->assertSame('80', StoreSetting::where('key', 'delivery_charge_inside_dhaka')->value('value'));
        $this->assertSame('160', StoreSetting::where('key', 'delivery_charge_outside_dhaka')->value('value'));
    }

    public function test_store_rejects_a_negative_charge(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings', [
            'delivery_charge_inside_dhaka' => -1,
            'delivery_charge_outside_dhaka' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('delivery_charge_inside_dhaka');
    }

    public function test_store_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        Sanctum::actingAs($userA);
        $this->postJson('/api/mobile/v1/settings', [
            'delivery_charge_inside_dhaka' => 99,
            'delivery_charge_outside_dhaka' => 199,
        ])->assertOk();

        app()->instance('currentTenant', $tenantB);
        $this->assertNull(StoreSetting::where('key', 'delivery_charge_inside_dhaka')->first());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings')->assertUnauthorized();
    }
}
