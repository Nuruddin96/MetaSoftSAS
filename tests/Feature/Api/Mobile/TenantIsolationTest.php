<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeOrder(int $tenantId, array $attrs = []): Order
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));

        return Order::create(array_merge([
            'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'Test Customer', 'customer_phone' => '01712345678',
            'subtotal' => 500, 'total' => 500, 'payment_method' => 'cod', 'status' => 'pending',
        ], $attrs));
    }

    public function test_authenticated_tenant_can_access_its_own_dashboard(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'today_orders', 'today_sales', 'pending_orders', 'courier_pending_count', 'total_customers',
                'today_expenses', 'low_stock_count', 'by_channel', 'top_districts', 'more_districts_count',
                'recent_orders', 'checklist' => ['product', 'logo', 'courier', 'order'],
                'new_messages', 'new_incomplete', 'total_products',
            ]);
    }

    /** Priority 3 parity pass — these four fields were previously missing from the mobile dashboard entirely. */
    public function test_dashboard_checklist_and_counts_reflect_real_tenant_state(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        \App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'slug' => 'p1', 'is_active' => 1]);
        \App\Models\Order::create([
            'tenant_id' => $tenant->id, 'source' => 'manual', 'channel' => 'call',
            'customer_name' => 'A', 'customer_phone' => '01712345678',
            'subtotal' => 100, 'total' => 100, 'payment_method' => 'cod', 'status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/dashboard')->assertOk();

        $response->assertJsonPath('checklist.product', true)
            ->assertJsonPath('checklist.order', true)
            ->assertJsonPath('checklist.logo', false)
            ->assertJsonPath('checklist.courier', false)
            ->assertJsonPath('total_products', 1)
            ->assertJsonPath('new_messages', 0)
            ->assertJsonPath('new_incomplete', 0);
    }

    /**
     * Regression test: a tenant with zero orders (e.g. immediately after
     * onboarding) made `by_channel` serialize as `[]` — `pluck('c',
     * 'channel')->toArray()` on an empty result is a plain PHP array with
     * no string keys, and json_encode() renders that as a JSON array, not
     * `{}`, even though every non-empty response already serializes
     * correctly as an object. The mobile client's DashboardSummary.fromJson
     * always casts this field `as Map<String, dynamic>?`, so the
     * inconsistent shape crashed it with a type-cast error right after
     * onboarding completion. assertJsonStructure above only checks the key
     * exists, not its JSON type, so it passed even with the bug — this test
     * decodes with `assoc: false` specifically to catch array-vs-object.
     */
    public function test_dashboard_by_channel_is_a_json_object_even_when_tenant_has_zero_orders(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/dashboard')->assertOk();

        $decoded = json_decode($response->getContent());
        $this->assertIsObject($decoded->by_channel);
        $this->assertSame([], (array) $decoded->by_channel);
    }

    public function test_tenant_cannot_view_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $orderA = $this->makeOrder($tenantA->id);

        Sanctum::actingAs($userB);

        $this->getJson("/api/mobile/v1/orders/{$orderA->id}")->assertNotFound();
    }

    public function test_tenant_order_list_never_includes_other_tenants_orders(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        $this->makeOrder($tenantA->id, ['order_number' => 'ORD-A1']);
        $this->makeOrder($tenantB->id, ['order_number' => 'ORD-B1']);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/mobile/v1/orders')->assertOk();
        $numbers = collect($response->json('data'))->pluck('order_number');

        $this->assertTrue($numbers->contains('ORD-A1'));
        $this->assertFalse($numbers->contains('ORD-B1'));
    }

    public function test_tenant_cannot_view_another_tenants_customer(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $customerA = DB::table('customers')->insertGetId([
            'tenant_id' => $tenantA->id, 'name' => 'Cust A', 'phone' => '01711111111',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($userB);

        $this->getJson("/api/mobile/v1/customers/{$customerA}")->assertNotFound();
    }

    public function test_tenant_customer_list_never_includes_other_tenants_customers(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        Customer::create(['tenant_id' => $tenantA->id, 'name' => 'A Customer', 'phone' => '01711111111']);
        Customer::create(['tenant_id' => $tenantB->id, 'name' => 'B Customer', 'phone' => '01722222222']);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/mobile/v1/customers')->assertOk();
        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('A Customer'));
        $this->assertFalse($names->contains('B Customer'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/dashboard')->assertUnauthorized();
    }
}
