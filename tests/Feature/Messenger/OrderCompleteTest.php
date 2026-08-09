<?php

namespace Tests\Feature\Messenger;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers OrderController::complete() — the operator-facing step that
 * attaches product/variant/qty/price to an Order auto-created from
 * Messenger (status=pending, no items yet) and flips it to confirmed,
 * reusing the exact item-attach/inventory-decrement logic store() uses
 * for a brand new manual order (see OrderController::attachItems()).
 */
class OrderCompleteTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function makePendingMessengerOrder(Tenant $tenant): Order
    {
        app()->instance('currentTenant', $tenant);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rahim',
            'phone' => '01712345678',
        ]);

        return Order::create([
            'tenant_id' => $tenant->id,
            'source' => 'messenger',
            'channel' => 'facebook',
            'messenger_psid' => 'psid-1',
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'status' => 'pending',
            'subtotal' => 0,
            'total' => 0,
        ]);
    }

    public function test_completing_a_pending_order_attaches_items_decrements_inventory_and_confirms(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 800]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), [
            'payment_method' => 'cod',
            'delivery_charge' => 60,
            'discount' => 0,
            'variant_ids' => [$variant->id],
            'quantities' => [2],
        ]);

        $response->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertEquals(1600, (float) $order->subtotal);
        $this->assertEquals(1660, (float) $order->total);
        $this->assertSame(1, $order->items()->count());

        $inventory = Inventory::where('variant_id', $variant->id)->first();
        $this->assertSame(98, $inventory->quantity, 'stock must decrement by the ordered quantity exactly once');

        $customer = Customer::find($order->customer_id);
        $this->assertSame(1, $customer->total_orders);
    }

    public function test_completing_an_already_completed_order_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makePendingMessengerOrder($tenant);
        $variant = $this->makeSellableVariant($tenant->id);

        $payload = [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ];

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), $payload)
            ->assertRedirect();

        // second attempt against the same now-confirmed order must not attach a second set of items
        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/complete'), $payload)
            ->assertStatus(409);

        $order->refresh();
        $this->assertSame(1, $order->items()->count());
    }

    public function test_tenant_cannot_complete_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $orderA = $this->makePendingMessengerOrder($tenantA);
        $variant = $this->makeSellableVariant($tenantA->id);

        // Tenant B's own panel URL/slug, but referencing tenant A's order
        // id — Order's BelongsToTenant global scope (keyed off the
        // URL-resolved currentTenant, i.e. tenant B here) must make
        // route-model-binding fail to find it, 404ing rather than ever
        // letting tenant B touch tenant A's order.
        $response = $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'orders/'.$orderA->id.'/complete'), [
            'payment_method' => 'cod',
            'variant_ids' => [$variant->id],
            'quantities' => [1],
        ]);

        $response->assertStatus(404);

        $orderA->refresh();
        $this->assertSame('pending', $orderA->status, "tenant A's order must remain untouched");
    }
}
