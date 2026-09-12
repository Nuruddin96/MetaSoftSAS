<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers the two new DashboardSummaryService fields added for the mobile
 * dashboard redesign (item 12): `today_additional_cost` (sum of today's
 * per-order `additional_amount`) and `courier_balances` (only for a
 * courier the tenant actually has connected, and only when the live
 * balance fetch just succeeded — never a fabricated/zero entry).
 */
class DashboardCourierBalanceAndAddCostTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeOrder(int $tenantId, array $attrs = []): Order
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return Order::create(array_merge([
            'tenant_id' => $tenantId, 'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'status' => 'confirmed', 'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod',
            'created_at' => now(),
        ], $attrs));
    }

    public function test_today_additional_cost_sums_only_todays_orders(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeOrder($tenant->id, ['additional_amount' => 100, 'created_at' => now()]);
        $this->makeOrder($tenant->id, ['additional_amount' => 50, 'created_at' => now()]);
        $this->makeOrder($tenant->id, ['additional_amount' => 999, 'created_at' => now()->subDays(2)]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertOk();
        $this->assertEquals(150.0, $response->json('today_additional_cost'));
    }

    public function test_today_additional_cost_is_zero_when_no_order_used_it(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertOk();
        $this->assertEquals(0.0, $response->json('today_additional_cost'));
    }

    public function test_courier_balances_includes_steadfast_when_connected_and_reachable(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        CourierSetting::create([
            'tenant_id' => $tenant->id, 'provider' => 'steadfast',
            'credentials' => ['api_key' => 'key', 'secret_key' => 'secret'], 'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::response(['current_balance' => 2500])]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertOk();
        $this->assertEquals([['provider' => 'steadfast', 'balance' => 2500.0]], $response->json('courier_balances'));
    }

    public function test_courier_balances_is_empty_when_no_courier_is_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertOk();
        $this->assertSame([], $response->json('courier_balances'));
        Http::assertNothingSent();
    }

    /** Never fabricate a balance — a failed live fetch must degrade to "just don't show it," not a fake ৳0. */
    public function test_courier_balances_is_empty_when_the_live_fetch_fails(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        CourierSetting::create([
            'tenant_id' => $tenant->id, 'provider' => 'steadfast',
            'credentials' => ['api_key' => 'key', 'secret_key' => 'secret'], 'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::response(['message' => 'Unauthorized'], 401)]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertOk();
        $this->assertSame([], $response->json('courier_balances'));
    }

    /** Tenant isolation: one tenant's courier balance must never leak into another's dashboard. */
    public function test_courier_balance_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        CourierSetting::create([
            'tenant_id' => $tenantA->id, 'provider' => 'steadfast',
            'credentials' => ['api_key' => 'tenant-a-key', 'secret_key' => 'tenant-a-secret'], 'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::response(['current_balance' => 9999])]);

        Sanctum::actingAs($userB);
        $response = $this->getJson('/api/mobile/v1/dashboard');

        $response->assertOk();
        $this->assertSame([], $response->json('courier_balances'));
        Http::assertNothingSent();
    }
}
