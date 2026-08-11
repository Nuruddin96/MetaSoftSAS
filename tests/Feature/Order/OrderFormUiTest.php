<?php

namespace Tests\Feature\Order;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Confirms the manual delivery-charge input is genuinely gone from both
 * order forms (New Order and Messenger Order confirm), not just ignored
 * server-side — a stale cached page or a browser extension re-adding a
 * field wouldn't be caught by the controller tests alone.
 */
class OrderFormUiTest extends TestCase
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

    public function test_new_order_form_has_no_manual_delivery_charge_input(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create'));

        $response->assertOk();
        $response->assertDontSee('name="delivery_charge"', false);
        $response->assertSee('প্রোডাক্ট সাবটোটাল');
        $response->assertSee('ডেলিভারি চার্জ');
        $response->assertSee('মোট টাকা');
    }

    public function test_messenger_order_confirm_form_has_no_manual_delivery_charge_input(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Rahim', 'phone' => '01712345678']);
        $order = Order::create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'messenger_psid' => 'psid-1', 'customer_id' => $customer->id,
            'customer_name' => $customer->name, 'customer_phone' => $customer->phone,
            'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/'.$order->id));

        $response->assertOk();
        $response->assertDontSee('name="delivery_charge"', false);
        $response->assertSee('প্রোডাক্ট সাবটোটাল');
        $response->assertSee('ডেলিভারি চার্জ');
        // Pre-filled from the Messenger-resolved customer/order data.
        $response->assertSee('Rahim');
        $response->assertSee('01712345678');
    }
}
