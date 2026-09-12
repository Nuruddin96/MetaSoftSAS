<?php

namespace Tests\Feature\Courier;

use App\Console\Commands\RefreshCourierStatuses;
use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Courier\SteadfastService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the real-time-ish courier status feature added on top of the
 * already-working manual refresh path (see CourierTest.php):
 * SteadfastService::getBalance() (dashboard courier-balance widget),
 * courier_status_checked_at stamping (dispatch + manual refresh), and the
 * new courier:refresh-statuses scheduled command (routes/console.php),
 * which is what actually makes courier status "sync periodically" rather
 * than only ever updating when a merchant manually taps refresh.
 */
class CourierBalanceAndScheduledRefreshTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerceSchema();
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function makeSteadfastSetting(int $tenantId): CourierSetting
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return CourierSetting::create([
            'tenant_id' => $tenantId, 'provider' => 'steadfast',
            'credentials' => ['api_key' => 'key-'.$tenantId, 'secret_key' => 'secret-'.$tenantId],
            'is_active' => true,
        ]);
    }

    protected function makeOrder(int $tenantId, array $attrs = []): Order
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return Order::create(array_merge([
            'tenant_id' => $tenantId, 'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'status' => 'processing', 'subtotal' => 500, 'total' => 550, 'payment_method' => 'cod',
        ], $attrs));
    }

    public function test_steadfast_get_balance_calls_the_real_endpoint_with_the_tenants_own_credentials(): void
    {
        Http::fake([
            'https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => 4820.5]),
        ]);

        $service = new SteadfastService('my-api-key', 'my-secret-key');
        $balance = $service->getBalance();

        $this->assertSame(4820.5, $balance);
        Http::assertSent(fn ($request) => $request->url() === 'https://portal.packzy.com/api/v1/get_balance'
            && $request->hasHeader('Api-Key', 'my-api-key')
            && $request->hasHeader('Secret-Key', 'my-secret-key'));
    }

    public function test_dispatching_an_order_stamps_courier_status_checked_at(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, ['status' => 'pending']);

        Http::fake(['https://portal.packzy.com/api/v1/create_order' => Http::response([
            'consignment' => ['consignment_id' => 'SF-1', 'tracking_code' => 'TRK-1'],
        ])]);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier'), ['provider' => 'steadfast']);

        $this->assertNotNull($order->refresh()->courier_status_checked_at);
    }

    public function test_manual_refresh_updates_courier_status_checked_at(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, [
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'SF-2',
            'courier_status' => 'pending', 'courier_status_checked_at' => now()->subDays(3),
        ]);

        Http::fake(['https://portal.packzy.com/api/v1/status_by_invoice/'.$order->order_number => Http::response(['delivery_status' => 'delivered'])]);

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'orders/'.$order->id.'/courier/refresh'));

        $order->refresh();
        $this->assertSame('delivered', $order->courier_status);
        $this->assertTrue($order->courier_status_checked_at->gt(now()->subMinute()));
    }

    public function test_scheduled_command_refreshes_a_non_terminal_dispatched_order(): void
    {
        $tenant = $this->makeTenant();
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, [
            'status' => 'processing', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-3', 'courier_status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['https://portal.packzy.com/api/v1/status_by_invoice/'.$order->order_number => Http::response(['delivery_status' => 'in_transit'])]);

        $this->artisan(RefreshCourierStatuses::class)->assertSuccessful();

        $order->refresh();
        $this->assertSame('in_transit', $order->courier_status);
        $this->assertNotNull($order->courier_status_checked_at);
    }

    public function test_scheduled_command_skips_orders_already_in_a_terminal_status(): void
    {
        $tenant = $this->makeTenant();
        $this->makeSteadfastSetting($tenant->id);
        $order = $this->makeOrder($tenant->id, [
            'status' => 'delivered', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-4', 'courier_status' => 'delivered',
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['https://portal.packzy.com/*' => Http::response(['delivery_status' => 'delivered'])]);

        $this->artisan(RefreshCourierStatuses::class)->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_scheduled_command_never_mixes_up_two_tenants_credentials(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        CourierSetting::create([
            'tenant_id' => $tenantA->id, 'provider' => 'steadfast',
            'credentials' => ['api_key' => 'tenant-a-key', 'secret_key' => 'tenant-a-secret'], 'is_active' => true,
        ]);
        app()->forgetInstance('currentTenant');
        $this->makeSteadfastSetting($tenantB->id);

        $orderA = $this->makeOrder($tenantA->id, [
            'status' => 'processing', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-A', 'courier_status' => 'pending',
        ]);
        $orderB = $this->makeOrder($tenantB->id, [
            'status' => 'processing', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-B', 'courier_status' => 'pending',
        ]);
        app()->forgetInstance('currentTenant');

        Http::fake(['https://portal.packzy.com/*' => Http::response(['delivery_status' => 'in_transit'])]);

        $this->artisan(RefreshCourierStatuses::class)->assertSuccessful();

        Http::assertSent(fn ($request) => $request->hasHeader('Api-Key', 'tenant-a-key'));
        Http::assertSent(fn ($request) => $request->hasHeader('Api-Key', 'key-'.$tenantB->id));
        $this->assertSame('in_transit', $orderA->refresh()->courier_status);
        $this->assertSame('in_transit', $orderB->refresh()->courier_status);
    }
}
