<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Customer;
use App\Services\AI\Tools\CustomerLookupTool;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class CustomerLookupToolTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function makeCustomer(int $tenantId, array $attrs = []): Customer
    {
        $id = DB::table('customers')->insertGetId(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'Karim',
            'phone' => '01700000001',
            'total_orders' => 0,
            'total_spent' => 0,
            'due_balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        return Customer::withoutGlobalScopes()->find($id);
    }

    public function test_returns_only_the_given_tenants_customers(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeCustomer($tenantA->id, ['phone' => '01700000001']);
        $this->makeCustomer($tenantB->id, ['phone' => '01700000002']);

        $result = (new CustomerLookupTool)->handle($tenantA->id, []);

        $this->assertSame(1, $result['count']);
    }

    public function test_never_trusts_a_tenant_id_supplied_inside_args(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeCustomer($tenantB->id, ['phone' => '01799999999']);

        $result = (new CustomerLookupTool)->handle($tenantA->id, ['tenant_id' => $tenantB->id]);

        $this->assertSame(0, $result['count'], "tenant A must never see tenant B's customers regardless of what args claims");
    }

    public function test_filters_by_exact_phone(): void
    {
        $tenant = $this->makeTenant();
        $this->makeCustomer($tenant->id, ['phone' => '01711111111']);
        $this->makeCustomer($tenant->id, ['phone' => '01722222222']);

        $result = (new CustomerLookupTool)->handle($tenant->id, ['phone' => '01711111111']);

        $this->assertSame(1, $result['count']);
    }

    public function test_filters_by_name_partial_match(): void
    {
        $tenant = $this->makeTenant();
        $this->makeCustomer($tenant->id, ['name' => 'Abdul Karim', 'phone' => '01711111111']);
        $this->makeCustomer($tenant->id, ['name' => 'Fatima Begum', 'phone' => '01722222222']);

        $result = (new CustomerLookupTool)->handle($tenant->id, ['name' => 'karim']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Abdul Karim', $result['customers'][0]['name']);
    }

    public function test_includes_order_history_and_due_balance(): void
    {
        $tenant = $this->makeTenant();
        $this->makeCustomer($tenant->id, ['total_orders' => 7, 'total_spent' => 15000, 'due_balance' => 500]);

        $result = (new CustomerLookupTool)->handle($tenant->id, []);

        $this->assertSame(7, $result['customers'][0]['total_orders']);
        $this->assertSame(15000.0, $result['customers'][0]['total_spent']);
        $this->assertSame(500.0, $result['customers'][0]['due_balance']);
    }

    public function test_no_matching_customer_returns_an_empty_result_not_an_error(): void
    {
        $tenant = $this->makeTenant();

        $result = (new CustomerLookupTool)->handle($tenant->id, ['phone' => '019999999999']);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['customers']);
    }
}
