<?php

namespace Tests\Feature\Courier;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers CourierController (send/refreshStatus) and OrderController::
 * bulkCourier() against the real Steadfast/Pathao request/response shapes
 * (SteadfastService/PathaoService are exercised for real, only the outer
 * Http::fake() boundary is mocked — nothing courier-specific is stubbed
 * inside this app's own code).
 */
class CourierTest extends TestCase
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

    protected function makeSteadfastSetting(int $tenantId, array $credentials = []): CourierSetting
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return CourierSetting::create([
            'tenant_id' => $tenantId,
            'provider' => 'steadfast',
            'credentials' => array_merge(['api_key' => 'key-'.$tenantId, 'secret_key' => 'secret-'.$tenantId], $credentials),
            'is_active' => true,
        ]);
    }

    protected function makeOrder(int $tenantId, array $attrs = []): Order
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return Order::create(array_merge([
            'tenant_id' => $tenantId,
            'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'customer_address' => 'Dhaka', 'status' => 'confirmed',
            'subtotal' => 500, 'total' => 550, 'payment_method' => 'cod',
        ], $attrs));
    }

    public function test_create_shipment_success_via_live_steadfast_request_shape(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, ['status' => 'pending']);

        Http::fake([
            'https://portal.packzy.com/api/v1/create_order' => Http::response([
                'status' => 200,
                'consignment' => ['consignment_id' => 'SF-100', 'tracking_code' => 'TRK-SF-100'],
            ]),
        ]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->url() === 'https://portal.packzy.com/api/v1/create_order'
            && $request['invoice'] === $order->order_number
            && $request->hasHeader('Api-Key', 'key-'.$tenant->id)
            && $request->hasHeader('Secret-Key', 'secret-'.$tenant->id));

        $order->refresh();
        $this->assertSame('steadfast', $order->courier_provider);
        $this->assertSame('SF-100', $order->courier_consignment_id);
        $this->assertSame('TRK-SF-100', $order->courier_tracking_code);
        $this->assertSame('pending', $order->courier_status);
        $this->assertSame('processing', $order->status, 'a pending order should be nudged to processing once dispatched');
    }

    public function test_create_shipment_api_failure_shows_friendly_error_and_leaves_order_untouched(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id);

        Http::fake([
            'https://portal.packzy.com/api/v1/create_order' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $order->refresh();
        $this->assertNull($order->courier_consignment_id, 'a failed API call must not leave the order half-updated');
        $this->assertNull($order->courier_provider);
    }

    public function test_duplicate_shipment_is_rejected_without_a_second_api_call(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, [
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'SF-ALREADY', 'courier_tracking_code' => 'TRK-ALREADY',
        ]);

        Http::fake(['https://portal.packzy.com/*' => Http::response(['consignment' => ['consignment_id' => 'SF-NEW']])]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('error');
        Http::assertNothingSent();

        $order->refresh();
        $this->assertSame('SF-ALREADY', $order->courier_consignment_id, 'the original consignment must not be overwritten');
    }

    public function test_delivered_order_cannot_be_sent_to_courier(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, ['status' => 'delivered']);

        Http::fake(['https://portal.packzy.com/*' => Http::response(['consignment' => ['consignment_id' => 'SF-X']])]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('error');
        Http::assertNothingSent();

        $this->assertNull($order->refresh()->courier_consignment_id);
    }

    public function test_returned_order_cannot_be_sent_to_courier(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, ['status' => 'returned']);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_cancelled_order_cannot_be_sent_to_courier_via_bulk(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $blocked = $this->makeOrder($tenant->id, ['status' => 'cancelled']);
        $allowed = $this->makeOrder($tenant->id, ['status' => 'confirmed']);

        Http::fake(['https://portal.packzy.com/*' => Http::response(['consignment' => ['consignment_id' => 'SF-BULK-1']])]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/bulk-courier'), [
            'order_ids' => [$blocked->id, $allowed->id],
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('success');

        $this->assertNull($blocked->refresh()->courier_consignment_id, 'a cancelled order must be silently excluded from bulk send');
        $this->assertNotNull($allowed->refresh()->courier_consignment_id);
        Http::assertSentCount(1);
    }

    public function test_bulk_courier_logs_the_failure_reason_per_order(): void
    {
        Log::spy();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id);

        Http::fake(['https://portal.packzy.com/*' => Http::response(['message' => 'Server error'], 500)]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/bulk-courier'), [
            'order_ids' => [$order->id],
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('error');

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => str_contains($message, 'bulkCourier') && $context['order_id'] === $order->id
        );
    }

    public function test_refresh_status_calls_live_steadfast_endpoint_and_updates_courier_status_only(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, [
            'status' => 'processing', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-200', 'courier_tracking_code' => 'TRK-200', 'courier_status' => 'pending',
        ]);

        Http::fake([
            'https://portal.packzy.com/api/v1/status_by_invoice/'.$order->order_number => Http::response(['delivery_status' => 'in_transit']),
        ]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier/refresh'));

        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('in_transit', $order->courier_status);
        $this->assertSame('processing', $order->status, 'refreshing courier_status must never touch the separate order status field');
    }

    public function test_refresh_status_on_an_unsent_order_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makeOrder($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier/refresh'));

        $response->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_tenant_cannot_refresh_status_on_another_tenants_order(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->makeSteadfastSetting($tenantB->id);
        $orderA = $this->makeOrder($tenantA->id, [
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'SF-A-1', 'courier_status' => 'pending',
        ]);

        Http::fake(['https://portal.packzy.com/*' => Http::response(['delivery_status' => 'delivered'])]);

        $response = $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'orders/'.$orderA->id.'/courier/refresh'));

        $response->assertStatus(404);
        Http::assertNothingSent();
        $this->assertSame('pending', $orderA->refresh()->courier_status);
    }

    public function test_courier_credentials_are_isolated_per_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        $this->makeSteadfastSetting($tenantA->id, ['api_key' => 'tenant-a-secret-key']);
        // Tenant B has no courier_settings row at all.
        $orderB = $this->makeOrder($tenantB->id);

        Http::fake(['https://portal.packzy.com/*' => Http::response(['consignment' => ['consignment_id' => 'SF-LEAK']])]);

        $response = $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'orders/'.$orderB->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('error');
        Http::assertNothingSent();
        $this->assertNull($orderB->refresh()->courier_consignment_id);
    }

    public function test_tenant_cannot_send_another_tenants_order_to_courier(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->makeSteadfastSetting($tenantB->id);
        $orderA = $this->makeOrder($tenantA->id);

        // Deliberately no Http::fake() pattern matching Steadfast's real
        // endpoint would be needed if this 404s correctly before ever
        // reaching CourierManager — asserting Http::assertNothingSent()
        // below is the real proof, not just the response code.
        Http::fake(['https://portal.packzy.com/*' => Http::response(['consignment' => ['consignment_id' => 'SF-CROSS-TENANT']])]);

        $response = $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'orders/'.$orderA->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertStatus(404);
        Http::assertNothingSent();
        $this->assertNull($orderA->refresh()->courier_consignment_id);
    }

    public function test_pathao_invalid_credentials_show_friendly_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        CourierSetting::create([
            'tenant_id' => $tenant->id, 'provider' => 'pathao',
            'credentials' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'username' => 'u', 'password' => 'p', 'store_id' => 'store-1'],
            'is_active' => true,
        ]);
        $order = $this->makeOrder($tenant->id);

        Http::fake([
            'https://api-hermes.pathao.com/aladdin/api/v1/issue-token' => Http::response(['message' => 'invalid credentials'], 401),
        ]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'pathao',
        ]);

        $response->assertSessionHas('error');
        $this->assertNull($order->refresh()->courier_consignment_id);
    }

    public function test_missing_courier_credentials_show_friendly_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $order = $this->makeOrder($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), [
            'provider' => 'steadfast',
        ]);

        $response->assertSessionHas('error');
        Http::assertNothingSent();
    }
}
