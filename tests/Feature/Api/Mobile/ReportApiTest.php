<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\ReportController — mirrors Tenant\ReportController's
 * real capability exactly (sales/profit-loss/locations/products), just as
 * JSON instead of a Blade view.
 */
class ReportApiTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();
    }

    protected function seedOrder(int $tenantId, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $tenantId, 'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'status' => 'delivered', 'subtotal' => 500, 'total' => 550, 'delivery_charge' => 50,
        ], $overrides));
    }

    public function test_sales_returns_current_month_totals_and_daily_breakdown(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->seedOrder($tenant->id, ['status' => 'delivered', 'total' => 550]);
        $this->seedOrder($tenant->id, ['status' => 'cancelled', 'total' => 999]);
        $this->seedOrder($tenant->id, ['status' => 'pending', 'total' => 200, 'created_at' => now()->subMonths(2)]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/reports/sales')->assertOk();
        $response->assertJsonPath('orders', 1);
        $this->assertEquals(550.0, $response->json('revenue'));
        $response->assertJsonStructure(['daily', 'by_status', 'by_source']);
    }

    public function test_profit_loss_computes_gross_and_net_profit(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->seedOrder($tenant->id, ['status' => 'delivered', 'total' => 1000, 'delivery_charge' => 100]);
        OrderItem::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'product_name' => 'Shirt',
            'variant_name' => 'M', 'unit_price' => 900, 'purchase_price' => 400, 'quantity' => 1, 'line_total' => 900,
        ]);
        // Inserted via the query builder, not Expense::create() — the
        // model's `expense_date` => 'date' cast re-serializes any assigned
        // value to "Y-m-d 00:00:00" on save; a real MySQL DATE column
        // silently truncates that back to a date, but SQLite's TEXT-backed
        // date column keeps the time component, which would otherwise
        // break the controller's (proven, unmodified) whereBetween()
        // string comparison in this test environment only. See
        // ExpenseApiTest's identical fixture for the full explanation.
        DB::table('expenses')->insert([
            'tenant_id' => $tenant->id, 'title' => 'Rent', 'amount' => 200, 'expense_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/reports/profit-loss')->assertOk();
        $this->assertEquals(1000.0, $response->json('revenue'));
        $this->assertEquals(100.0, $response->json('shipping'));
        $this->assertEquals(400.0, $response->json('cogs'));
        $this->assertEquals(200.0, $response->json('expenses'));
        // gross = 1000 - 100 - 400 = 500; net = 500 - 200 = 300
        $this->assertEquals(500.0, $response->json('gross_profit'));
        $this->assertEquals(300.0, $response->json('net_profit'));
    }

    public function test_locations_groups_orders_by_division_and_district(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        DB::table('bd_divisions')->insert(['id' => 1, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
        DB::table('bd_districts')->insert(['id' => 1, 'division_id' => 1, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
        $this->seedOrder($tenant->id, ['status' => 'delivered', 'division_id' => 1, 'district_id' => 1, 'total' => 300]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/reports/locations')->assertOk();
        $response->assertJsonPath('by_division.0.name', 'ঢাকা');
        $response->assertJsonPath('by_division.0.orders', 1);
        $response->assertJsonPath('by_district.0.name', 'ঢাকা');
    }

    public function test_products_returns_top_sellers_by_quantity(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->seedOrder($tenant->id, ['status' => 'delivered']);
        OrderItem::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id, 'product_name' => 'Shirt',
            'variant_name' => 'M', 'unit_price' => 500, 'purchase_price' => 300, 'quantity' => 3, 'line_total' => 1500,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/reports/products')->assertOk();
        $response->assertJsonPath('top.0.product_name', 'Shirt');
        $response->assertJsonPath('top.0.qty', 3);
        $this->assertEquals(600.0, $response->json('top.0.profit'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/reports/sales')->assertUnauthorized();
    }
}
