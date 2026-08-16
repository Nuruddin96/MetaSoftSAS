<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Order;
use App\Services\AI\Tools\UpdateOrderStatusTool;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class UpdateOrderStatusToolTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function makeOrder(int $tenantId, array $attrs = []): Order
    {
        $id = DB::table('orders')->insertGetId(array_merge([
            'tenant_id' => $tenantId, 'order_number' => 'ORD-'.random_int(100000, 999999),
            'source' => 'manual', 'channel' => 'others', 'customer_name' => 'Rahim', 'customer_phone' => '01700000001',
            'total' => 500, 'status' => 'pending', 'payment_status' => 'unpaid', 'payment_method' => 'cod',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return Order::withoutGlobalScopes()->find($id);
    }

    public function test_is_mutating(): void
    {
        $this->assertTrue((new UpdateOrderStatusTool)->isMutating());
    }

    public function test_preview_rejects_an_invalid_status_value(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id);

        $preview = (new UpdateOrderStatusTool)->preview($tenant->id, ['order_number' => $order->order_number, 'status' => 'archived']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_rejects_an_unknown_order_number(): void
    {
        $tenant = $this->makeTenant();

        $preview = (new UpdateOrderStatusTool)->preview($tenant->id, ['order_number' => 'ORD-DOES-NOT-EXIST', 'status' => 'confirmed']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_never_matches_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $order = $this->makeOrder($tenantB->id);

        $preview = (new UpdateOrderStatusTool)->preview($tenantA->id, ['order_number' => $order->order_number, 'status' => 'confirmed']);

        $this->assertArrayHasKey('error', $preview, "tenant A must never be able to update tenant B's order status");
    }

    public function test_preview_rejects_setting_the_same_status_it_already_has(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id, ['status' => 'shipped']);

        $preview = (new UpdateOrderStatusTool)->preview($tenant->id, ['order_number' => $order->order_number, 'status' => 'shipped']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_succeeds_and_resolves_order_id(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id, ['status' => 'pending']);

        $preview = (new UpdateOrderStatusTool)->preview($tenant->id, ['order_number' => $order->order_number, 'status' => 'confirmed']);

        $this->assertArrayHasKey('summary', $preview);
        $this->assertSame($order->id, $preview['resolved_args']['order_id']);
        $this->assertSame('confirmed', $preview['resolved_args']['status']);
        $this->assertStringContainsString($order->order_number, $preview['summary']);
    }

    public function test_handle_updates_the_status(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id, ['status' => 'pending']);

        $result = (new UpdateOrderStatusTool)->handle($tenant->id, ['order_id' => $order->id, 'status' => 'shipped']);

        $this->assertTrue($result['success']);
        $order->refresh();
        $this->assertSame('shipped', $order->status);
    }

    public function test_handle_sets_confirmed_at_when_confirming(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id, ['status' => 'pending', 'confirmed_at' => null]);

        (new UpdateOrderStatusTool)->handle($tenant->id, ['order_id' => $order->id, 'status' => 'confirmed']);

        $order->refresh();
        $this->assertNotNull($order->confirmed_at);
        $this->assertNull($order->delivered_at);
    }

    public function test_handle_sets_delivered_at_when_delivering(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id, ['status' => 'shipped', 'delivered_at' => null]);

        (new UpdateOrderStatusTool)->handle($tenant->id, ['order_id' => $order->id, 'status' => 'delivered']);

        $order->refresh();
        $this->assertNotNull($order->delivered_at);
    }

    public function test_handle_refuses_an_order_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $order = $this->makeOrder($tenantB->id, ['status' => 'pending']);

        $result = (new UpdateOrderStatusTool)->handle($tenantA->id, ['order_id' => $order->id, 'status' => 'cancelled']);

        $this->assertFalse($result['success']);
        $order->refresh();
        $this->assertSame('pending', $order->status, "tenant A's confirm request must never be able to change tenant B's order status");
    }

    public function test_handle_rejects_an_invalid_status_even_if_it_somehow_reached_here(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id, ['status' => 'pending']);

        $result = (new UpdateOrderStatusTool)->handle($tenant->id, ['order_id' => $order->id, 'status' => 'archived']);

        $this->assertFalse($result['success']);
        $order->refresh();
        $this->assertSame('pending', $order->status);
    }
}
