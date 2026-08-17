<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\StoreSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * OrderController::store() (the "New Order" manual creation form) — proves
 * the delivery charge is always server-computed via DeliveryChargeService
 * from division_id, and that the form's removed manual field has no
 * remaining effect even if a request still submits one.
 */
class OrderStoreDeliveryChargeTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected int $dhakaDivisionId;
    protected int $otherDivisionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        DB::table('bd_divisions')->insert([
            ['id' => 1, 'name' => 'Barishal', 'bn_name' => 'বরিশাল'],
            ['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
        ]);
        $this->dhakaDivisionId = 3;
        $this->otherDivisionId = 1;
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function basePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Karim',
            'customer_phone' => '01712345678',
            'channel' => 'call',
            'payment_method' => 'cod',
        ], $overrides);
    }

    public function test_dhaka_division_applies_the_configured_inside_dhaka_charge(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '80']);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), $this->basePayload([
            'division_id' => $this->dhakaDivisionId,
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]));

        $response->assertRedirect();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->first();
        $this->assertEquals(80.0, (float) $order->delivery_charge);
        $this->assertEquals(580.0, (float) $order->total);
    }

    public function test_non_dhaka_division_applies_the_configured_outside_dhaka_charge(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_outside_dhaka', 'value' => '150']);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), $this->basePayload([
            'division_id' => $this->otherDivisionId,
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]));

        $response->assertRedirect();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->first();
        $this->assertEquals(150.0, (float) $order->delivery_charge);
    }

    public function test_missing_address_division_defaults_to_the_outside_dhaka_charge(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        // No division_id at all in the payload.
        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), $this->basePayload([
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]));

        $response->assertRedirect();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->first();
        $this->assertEquals(120.0, (float) $order->delivery_charge, 'unconfigured default outside-Dhaka charge');
    }

    /** The manual delivery-charge form field no longer exists — this proves the server never trusted it even when it did. */
    public function test_a_manually_submitted_delivery_charge_is_completely_ignored(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), $this->basePayload([
            'division_id' => $this->dhakaDivisionId,
            'delivery_charge' => 1, // an attacker/stale-client-shaped value — must have zero effect
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]));

        $response->assertRedirect();
        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->first();
        $this->assertEquals(60.0, (float) $order->delivery_charge, 'must be the server-computed Dhaka charge, never the submitted 1');
    }

    public function test_total_equals_subtotal_plus_delivery_charge_minus_discount(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '80']);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders'), $this->basePayload([
            'division_id' => $this->dhakaDivisionId,
            'discount' => 50,
            'variant_ids' => [$variant->id],
            'quantities' => [2],
        ]))->assertRedirect();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest()->first();
        // subtotal 1000 - discount 50 + delivery 80 = 1030
        $this->assertEquals(1000.0, (float) $order->subtotal);
        $this->assertEquals(1030.0, (float) $order->total);
    }
}
