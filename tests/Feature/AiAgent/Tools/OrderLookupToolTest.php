<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Order;
use App\Services\AI\Tools\OrderLookupTool;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\Tools\OrderLookupTool. Deliberately calls
 * ->handle() directly rather than through AiToolRegistry (that's what
 * AiToolRegistryTest already covers) — this file is about the tool's own
 * query/output correctness and its tenant scoping.
 */
class OrderLookupToolTest extends TestCase
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
            'tenant_id' => $tenantId,
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'source' => 'web',
            'customer_name' => 'Rahim',
            'customer_phone' => '01700000001',
            'subtotal' => 500,
            'total' => 560,
            'delivery_charge' => 60,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return Order::withoutGlobalScopes()->find($id);
    }

    public function test_returns_only_the_given_tenants_orders(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrder($tenantA->id, ['customer_phone' => '01700000001']);
        $this->makeOrder($tenantB->id, ['customer_phone' => '01700000001']);

        $result = (new OrderLookupTool)->handle($tenantA->id, []);

        $this->assertSame(1, $result['count']);
    }

    public function test_never_trusts_a_tenant_id_supplied_inside_args(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrder($tenantB->id, ['customer_phone' => '01700000002']);

        // Even if the AI (or an adversarial prompt) puts a tenant_id
        // inside the arguments, the tool must ignore it entirely and use
        // only the trusted $tenantId parameter.
        $result = (new OrderLookupTool)->handle($tenantA->id, ['tenant_id' => $tenantB->id]);

        $this->assertSame(0, $result['count'], "tenant A's lookup must never return tenant B's orders regardless of what args claims");
    }

    public function test_filters_by_exact_order_number(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['order_number' => 'ORD-000123']);
        $this->makeOrder($tenant->id, ['order_number' => 'ORD-000456']);

        $result = (new OrderLookupTool)->handle($tenant->id, ['order_number' => 'ORD-000123']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('ORD-000123', $result['orders'][0]['order_number']);
    }

    public function test_filters_by_customer_phone(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['customer_phone' => '01711111111']);
        $this->makeOrder($tenant->id, ['customer_phone' => '01722222222']);

        $result = (new OrderLookupTool)->handle($tenant->id, ['customer_phone' => '01711111111']);

        $this->assertSame(1, $result['count']);
    }

    public function test_filters_by_status(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['status' => 'pending']);
        $this->makeOrder($tenant->id, ['status' => 'delivered']);

        $result = (new OrderLookupTool)->handle($tenant->id, ['status' => 'delivered']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('delivered', $result['orders'][0]['status']);
    }

    public function test_rejects_an_invalid_status_value_rather_than_erroring(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['status' => 'pending']);

        // 'DROP TABLE orders' or any other non-enum value must simply be
        // ignored as a filter, never passed through to the query raw.
        $result = (new OrderLookupTool)->handle($tenant->id, ['status' => 'not-a-real-status']);

        $this->assertSame(1, $result['count'], 'an invalid status filter must be ignored, not error or match nothing');
    }

    public function test_limit_is_clamped_between_1_and_20(): void
    {
        $tenant = $this->makeTenant();
        for ($i = 0; $i < 3; $i++) {
            $this->makeOrder($tenant->id);
        }

        $result = (new OrderLookupTool)->handle($tenant->id, ['limit' => 999]);
        $this->assertLessThanOrEqual(20, count($result['orders']));

        $result = (new OrderLookupTool)->handle($tenant->id, ['limit' => 0]);
        $this->assertSame(1, count($result['orders']), 'limit must be clamped to at least 1');
    }

    public function test_includes_line_items(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id);
        DB::table('order_items')->insert([
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'product_name' => 'Test Product',
            'variant_name' => 'Red / M',
            'unit_price' => 500,
            'quantity' => 1,
            'line_total' => 500,
        ]);

        $result = (new OrderLookupTool)->handle($tenant->id, ['order_number' => $order->order_number]);

        $this->assertCount(1, $result['orders'][0]['items']);
        $this->assertSame('Test Product', $result['orders'][0]['items'][0]['product_name']);
    }

    public function test_no_matching_order_returns_an_empty_result_not_an_error(): void
    {
        $tenant = $this->makeTenant();

        $result = (new OrderLookupTool)->handle($tenant->id, ['order_number' => 'ORD-DOES-NOT-EXIST']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['orders']);
    }
}
