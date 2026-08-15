<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Services\AI\Tools\CourierActionTool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class CourierActionToolTest extends TestCase
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
            'total' => 500, 'status' => 'confirmed', 'payment_status' => 'unpaid', 'payment_method' => 'cod',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return Order::withoutGlobalScopes()->find($id);
    }

    protected function giveCourierCredentials(int $tenantId, string $provider = 'steadfast'): void
    {
        // credentials casts as encrypted:array on the model — inserting
        // via DB::table() directly would store plain JSON that the model
        // then fails to decrypt when CourierManager reads it back.
        CourierSetting::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'credentials' => $provider === 'pathao'
                ? ['client_id' => 'id', 'client_secret' => 'secret', 'username' => 'u', 'password' => 'p', 'store_id' => 's']
                : ['api_key' => 'k', 'secret_key' => 's'],
            'is_active' => 1,
        ]);
    }

    public function test_is_mutating(): void
    {
        $this->assertTrue((new CourierActionTool)->isMutating());
    }

    public function test_preview_rejects_an_unknown_order_number(): void
    {
        $tenant = $this->makeTenant();

        $preview = (new CourierActionTool)->preview($tenant->id, ['order_number' => 'ORD-DOES-NOT-EXIST', 'provider' => 'steadfast']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_never_matches_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $order = $this->makeOrder($tenantB->id);

        $preview = (new CourierActionTool)->preview($tenantA->id, ['order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertArrayHasKey('error', $preview, "tenant A must never be able to courier-send tenant B's order");
    }

    public function test_preview_rejects_an_order_already_sent_to_courier(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $order = $this->makeOrder($tenant->id, ['courier_consignment_id' => 'CS-1', 'courier_provider' => 'steadfast']);
        $this->giveCourierCredentials($tenant->id);

        $preview = (new CourierActionTool)->preview($tenant->id, ['order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_rejects_a_blocked_status(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $order = $this->makeOrder($tenant->id, ['status' => 'cancelled']);
        $this->giveCourierCredentials($tenant->id);

        $preview = (new CourierActionTool)->preview($tenant->id, ['order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_rejects_when_no_courier_credentials_are_configured(): void
    {
        $tenant = $this->makeTenant();
        $order = $this->makeOrder($tenant->id);
        // No giveCourierCredentials() call.

        $preview = (new CourierActionTool)->preview($tenant->id, ['order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_succeeds_and_resolves_order_id(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $order = $this->makeOrder($tenant->id);
        $this->giveCourierCredentials($tenant->id);

        $preview = (new CourierActionTool)->preview($tenant->id, ['order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertArrayHasKey('summary', $preview);
        $this->assertSame($order->id, $preview['resolved_args']['order_id']);
        $this->assertStringContainsString($order->order_number, $preview['summary']);
    }

    public function test_handle_sends_the_order_and_updates_it(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $order = $this->makeOrder($tenant->id, ['status' => 'pending']);
        $this->giveCourierCredentials($tenant->id);

        Http::fake(['*' => Http::response(['consignment' => ['consignment_id' => 'CS-999', 'tracking_code' => 'TRK-999']], 200)]);

        $result = (new CourierActionTool)->handle($tenant->id, ['order_id' => $order->id, 'order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertTrue($result['success']);

        $order->refresh();
        $this->assertSame('steadfast', $order->courier_provider);
        $this->assertSame('CS-999', $order->courier_consignment_id);
        $this->assertSame('processing', $order->status, 'a pending order must transition to processing once sent');
    }

    public function test_handle_refuses_an_order_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $order = $this->makeOrder($tenantB->id);
        $this->giveCourierCredentials($tenantA->id);

        Http::fake(['*' => Http::response(['consignment_id' => 'CS-1', 'tracking_code' => 'TRK-1'], 200)]);

        $result = (new CourierActionTool)->handle($tenantA->id, ['order_id' => $order->id, 'order_number' => $order->order_number, 'provider' => 'steadfast']);

        $this->assertFalse($result['success']);
        $order->refresh();
        $this->assertNull($order->courier_consignment_id, "tenant A's confirm request must never be able to dispatch tenant B's order");
    }

    public function test_handle_re_verifies_it_was_not_already_sent_between_preview_and_confirm(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $order = $this->makeOrder($tenant->id);
        $this->giveCourierCredentials($tenant->id);

        $preview = (new CourierActionTool)->preview($tenant->id, ['order_number' => $order->order_number, 'provider' => 'steadfast']);

        // Sent through the normal panel in the meantime.
        $order->update(['courier_consignment_id' => 'CS-ALREADY', 'courier_provider' => 'pathao']);

        $result = (new CourierActionTool)->handle($tenant->id, $preview['resolved_args']);

        $this->assertFalse($result['success']);
    }
}
