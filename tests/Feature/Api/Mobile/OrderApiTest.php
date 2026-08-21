<?php

namespace Tests\Feature\Api\Mobile;

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
