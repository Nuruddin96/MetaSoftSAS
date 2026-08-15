<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Order;
use App\Services\AI\Tools\SalesReportTool;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class SalesReportToolTest extends TestCase
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
            'total' => 500,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'cod',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return Order::withoutGlobalScopes()->find($id);
    }

    public function test_only_counts_the_given_tenants_orders(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrder($tenantA->id, ['total' => 500]);
        $this->makeOrder($tenantB->id, ['total' => 999999]);

        $result = (new SalesReportTool)->handle($tenantA->id, []);

        $this->assertSame(1, $result['total_orders']);
        $this->assertSame(500.0, $result['total_revenue']);
    }

    public function test_never_trusts_a_tenant_id_supplied_inside_args(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeOrder($tenantB->id, ['total' => 999999]);

        $result = (new SalesReportTool)->handle($tenantA->id, ['tenant_id' => $tenantB->id]);

        $this->assertSame(0, $result['total_orders'], "tenant A's report must never include tenant B's revenue regardless of what args claims");
    }

    public function test_excludes_cancelled_and_returned_orders(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['status' => 'delivered', 'total' => 500]);
        $this->makeOrder($tenant->id, ['status' => 'cancelled', 'total' => 300]);
        $this->makeOrder($tenant->id, ['status' => 'returned', 'total' => 200]);

        $result = (new SalesReportTool)->handle($tenant->id, []);

        $this->assertSame(1, $result['total_orders']);
        $this->assertSame(500.0, $result['total_revenue']);
    }

    public function test_defaults_to_the_current_calendar_month(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['created_at' => now(), 'total' => 500]);
        $this->makeOrder($tenant->id, ['created_at' => now()->subMonths(2), 'total' => 999999]);

        $result = (new SalesReportTool)->handle($tenant->id, []);

        $this->assertSame(1, $result['total_orders']);
    }

    public function test_respects_an_explicit_date_range(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['created_at' => '2026-01-15 10:00:00', 'total' => 500]);
        $this->makeOrder($tenant->id, ['created_at' => '2026-03-15 10:00:00', 'total' => 999999]);

        $result = (new SalesReportTool)->handle($tenant->id, ['from' => '2026-01-01', 'to' => '2026-01-31']);

        $this->assertSame(1, $result['total_orders']);
        $this->assertSame('2026-01-01', $result['from']);
        $this->assertSame('2026-01-31', $result['to']);
    }

    public function test_breaks_down_by_status_and_source(): void
    {
        $tenant = $this->makeTenant();
        $this->makeOrder($tenant->id, ['status' => 'delivered', 'source' => 'web', 'total' => 500]);
        $this->makeOrder($tenant->id, ['status' => 'pending', 'source' => 'pos', 'total' => 300]);

        $result = (new SalesReportTool)->handle($tenant->id, []);

        $this->assertCount(2, $result['by_status']);
        $this->assertCount(2, $result['by_source']);
    }
}
