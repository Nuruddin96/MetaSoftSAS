<?php

namespace Tests\Feature\Tenant;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the new Steadfast Center hub (SteadfastCenterController) opened
 * from the dashboard's Steadfast Balance tile: balance display/refresh,
 * that only orders actually sent to Steadfast show up as "parcels" (not
 * every order), and tenant isolation — same real-Http::fake()-boundary
 * style as CourierTest.
 */
class SteadfastCenterTest extends TestCase
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

    protected function makeSteadfastSetting(int $tenantId): CourierSetting
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return CourierSetting::create([
            'tenant_id' => $tenantId,
            'provider' => 'steadfast',
            'credentials' => ['api_key' => 'key-'.$tenantId, 'secret_key' => 'secret-'.$tenantId],
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

    public function test_dashboard_balance_tile_links_to_steadfast_center(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);

        Http::fake([
            'https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => 4250]),
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response->assertOk();
        $response->assertSee('Steadfast ব্যালেন্স');
        $response->assertSee($this->panelUrl($tenant, 'steadfast'), false);
        $response->assertSee('৳4,250', false);
    }

    public function test_dashboard_shows_not_connected_when_no_steadfast_credentials(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response->assertOk();
        $response->assertSee('সংযুক্ত নয়');
    }

    public function test_center_shows_only_orders_actually_sent_to_steadfast(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);

        $sent = $this->makeOrder($tenant->id, [
            'status' => 'shipped', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-CN-1', 'courier_status' => 'pending',
        ]);
        $notSent = $this->makeOrder($tenant->id, ['status' => 'pending']);
        $sentToOtherCourier = $this->makeOrder($tenant->id, [
            'status' => 'shipped', 'courier_provider' => 'pathao', 'courier_consignment_id' => 'PA-1',
        ]);

        Http::fake([
            'https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => 1000]),
            'https://portal.packzy.com/api/v1/payments' => Http::response(['status' => 200, 'payments' => []]),
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'steadfast'));

        $response->assertOk();
        $response->assertSee($sent->order_number);
        $response->assertDontSee($notSent->order_number);
        $response->assertDontSee($sentToOtherCourier->order_number);
    }

    public function test_center_never_shows_another_tenants_parcels(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->makeSteadfastSetting($tenantA->id);
        $this->makeSteadfastSetting($tenantB->id);

        $orderB = $this->makeOrder($tenantB->id, [
            'status' => 'shipped', 'courier_provider' => 'steadfast',
            'courier_consignment_id' => 'SF-OTHER-TENANT', 'courier_status' => 'pending',
        ]);

        Http::fake([
            'https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => 1000]),
            'https://portal.packzy.com/api/v1/payments' => Http::response(['status' => 200, 'payments' => []]),
        ]);

        $response = $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'steadfast'));

        $response->assertOk();
        $response->assertDontSee($orderB->order_number);
        $response->assertDontSee('SF-OTHER-TENANT');
    }

    public function test_center_renders_gracefully_when_steadfast_not_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'steadfast'));

        $response->assertOk();
        $response->assertSee('সংযুক্ত নয়');
    }

    public function test_balance_refresh_bypasses_cache_and_calls_live_endpoint(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);

        Http::fake([
            'https://portal.packzy.com/api/v1/get_balance' => Http::sequence()
                ->push(['status' => 200, 'current_balance' => 1000])
                ->push(['status' => 200, 'current_balance' => 2000]),
        ]);

        // Prime the cache.
        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'steadfast/balance/refresh'));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        Http::assertSentCount(2);
    }

    public function test_payment_request_card_states_no_official_submit_endpoint_exists(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeSteadfastSetting($tenant->id);

        Http::fake([
            'https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => 1000]),
            'https://portal.packzy.com/api/v1/payments' => Http::response(['status' => 200, 'payments' => []]),
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'steadfast'));

        $response->assertOk();
        $response->assertSee('পেমেন্ট/সেটেলমেন্ট রিকোয়েস্ট সাবমিট করার কোনো অফিসিয়াল endpoint নেই', false);
    }
}
