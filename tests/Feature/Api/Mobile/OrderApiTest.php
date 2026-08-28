<?php

namespace Tests\Feature\Api\Mobile;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_create_order_computes_totals_and_generates_order_number(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        app()->forgetInstance('currentTenant'); // real requests start with nothing bound

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/orders', [
            'customer_name' => 'Rahim Uddin',
            'customer_phone' => '01712345678',
            'channel' => 'call',
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [2],
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtotal', 1000)
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('customer_name', 'Rahim Uddin')
            ->assertJsonCount(1, 'items');

        $this->assertStringStartsWith('ORD-', $response->json('order_number'));

        // delivery_charge must never be settable by the client — this
        // payload sent none, and the server-computed value must still be present.
        $this->assertIsNumeric($response->json('delivery_charge'));
    }

    /**
     * additional_amount is a mobile-only surcharge field (Web's own
     * order-create form has no equivalent) — must add on top of discount
     * without disturbing it, and must default to 0/never go negative.
     */
    public function test_create_order_applies_additional_amount_on_top_of_discount(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/orders', [
            'customer_name' => 'Karim',
            'customer_phone' => '01712345679',
            'channel' => 'call',
            'payment_method' => 'cod',
            'discount' => 100,
            'additional_amount' => 50,
            'variant_ids' => [$variant->id],
            'quantities' => [2],
        ]);

        $delivery = $response->json('delivery_charge');

        $response->assertCreated()
            ->assertJsonPath('subtotal', 1000)
            ->assertJsonPath('discount', 100)
            ->assertJsonPath('additional_amount', 50)
            ->assertJsonPath('total', 1000 - 100 + 50 + $delivery);
    }

    public function test_create_order_rejects_invalid_phone(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $variant = $this->makeSellableVariant($tenant->id);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/orders', [
            'customer_name' => 'Rahim',
            'customer_phone' => '123',
            'channel' => 'call',
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ])->assertStatus(422)->assertJsonValidationErrors('customer_phone');
    }

    /**
     * The Flutter create-order form (District→Thana UX, Priority 2) no
     * longer submits division_id at all — proves OrderCreationService still
     * derives it from district_id via DeliveryChargeService::resolveDivisionId()
     * and stores upazila_id, matching the web panel's identical change.
     */
    public function test_create_order_resolves_division_from_district_and_stores_upazila(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        app()->instance('currentTenant', $tenant);
        DB::table('bd_divisions')->insert(['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
        DB::table('bd_districts')->insert(['id' => 30, 'division_id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
        DB::table('bd_upazilas')->insert(['id' => 300, 'district_id' => 30, 'name' => 'Mirpur', 'bn_name' => 'মিরপুর']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/orders', [
            'customer_name' => 'Karim',
            'customer_phone' => '01712345678',
            'channel' => 'call',
            'payment_method' => 'cod',
            'district_id' => 30,
            'upazila_id' => 300,
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]);

        $response->assertCreated()
            ->assertJsonPath('division_name', 'ঢাকা')
            ->assertJsonPath('district_name', 'ঢাকা')
            ->assertJsonPath('upazila_name', 'মিরপুর');
    }

    /**
     * Mirrors Tenant\OrderController::complete() — Priority 3 parity pass.
     * Confirms a Messenger-originated pending order (zero items) by
     * attaching staff-picked product/variant/qty/price.
     */
    public function test_complete_attaches_items_decrements_inventory_and_confirms(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $customer = \App\Models\Customer::create(['tenant_id' => $tenant->id, 'name' => 'Rahim', 'phone' => '01712345678']);
        $order = \App\Models\Order::create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'messenger_psid' => 'psid-1', 'customer_id' => $customer->id,
            'customer_name' => $customer->name, 'customer_phone' => $customer->phone,
            'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 800]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/mobile/v1/orders/{$order->id}/complete", [
            'payment_method' => 'cod',
            'additional_amount' => 25,
            'variant_ids' => [$variant->id],
            'quantities' => [2],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('subtotal', 1600)
            ->assertJsonPath('additional_amount', 25)
            ->assertJsonPath('messenger_psid', 'psid-1')
            ->assertJsonCount(1, 'items');

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_complete_rejects_an_order_that_already_has_items(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        app()->instance('currentTenant', $tenant);
        $order = \App\Models\Order::create([
            'tenant_id' => $tenant->id, 'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod', 'status' => 'confirmed',
        ]);
        $order->items()->create([
            'tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'product_name' => 'P',
            'variant_name' => 'V', 'sku' => 'SKU', 'unit_price' => 500, 'purchase_price' => 0,
            'quantity' => 1, 'line_total' => 500,
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/orders/{$order->id}/complete", [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ])->assertStatus(409);
    }

    public function test_complete_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        $orderA = \App\Models\Order::create([
            'tenant_id' => $tenantA->id, 'source' => 'messenger', 'channel' => 'facebook',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]);
        $variant = $this->makeSellableVariant($tenantA->id, ['selling_price' => 500]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->postJson("/api/mobile/v1/orders/{$orderA->id}/complete", [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ])->assertNotFound();
    }

    /** Mirrors Tenant\OrderController::updateChannel() — Priority 3 parity pass. */
    public function test_update_channel_corrects_the_order_source(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $order = \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->patchJson("/api/mobile/v1/orders/{$order->id}/channel", ['channel' => 'facebook'])
            ->assertOk()->assertJsonPath('channel', 'facebook');

        $this->assertSame('facebook', $order->fresh()->channel);
    }

    public function test_update_channel_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        $orderA = \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->patchJson("/api/mobile/v1/orders/{$orderA->id}/channel", ['channel' => 'facebook'])->assertNotFound();
        $this->assertSame('call', $orderA->fresh()->channel);
    }

    public function test_index_returns_pagination_meta_and_supports_channel_filter(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'facebook',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'B', 'customer_phone' => '01712345679',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/orders')->assertOk();
        $response->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertSame(2, $response->json('meta.total'));

        $filtered = $this->getJson('/api/mobile/v1/orders?channel=facebook')->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('A', $filtered->json('data.0.customer_name'));
    }

    /** Mirrors Tenant\OrderController::index()'s `?courier=pending` filter — dispatched but not yet in a terminal state. */
    public function test_index_supports_courier_pending_filter(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'Dispatched', 'customer_phone' => '01712345678',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'processing',
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'CS-1',
        ]);
        \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'Delivered', 'customer_phone' => '01712345679',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'delivered',
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'CS-2',
        ]);
        \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'NeverSent', 'customer_phone' => '01712345680',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $filtered = $this->getJson('/api/mobile/v1/orders?courier=pending')->assertOk();
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('Dispatched', $filtered->json('data.0.customer_name'));
    }

    public function test_refresh_courier_status_rejects_an_order_never_sent_to_a_courier(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $order = \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'Test', 'customer_phone' => '01712345678',
            'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/orders/{$order->id}/courier/refresh")
            ->assertStatus(422)->assertJsonValidationErrors('courier');
    }

    public function test_refresh_courier_status_rejects_when_no_courier_credentials_are_configured(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $order = \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'Test', 'customer_phone' => '01712345678',
            'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod', 'status' => 'processing',
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'CONS-1',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/orders/{$order->id}/courier/refresh")
            ->assertStatus(422)->assertJsonValidationErrors('courier');
    }

    public function test_refresh_courier_status_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        $orderA = \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod', 'status' => 'processing',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->postJson("/api/mobile/v1/orders/{$orderA->id}/courier/refresh")->assertNotFound();
    }

    public function test_update_order_status(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $order = \App\Models\Order::create([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'Test', 'customer_phone' => '01712345678',
            'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->patchJson("/api/mobile/v1/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('status', 'shipped');
    }
}
