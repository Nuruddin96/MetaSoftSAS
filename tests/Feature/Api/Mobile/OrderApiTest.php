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
