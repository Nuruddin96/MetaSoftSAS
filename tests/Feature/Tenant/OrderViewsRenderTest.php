<?php

namespace Tests\Feature\Tenant;

use App\Models\Customer;
use App\Models\MessengerMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Smoke coverage for the mobile-optimized orders/index and orders/show
 * views — no dedicated GET-rendering test existed for either before this
 * (OrderCompleteTest only posts to the complete action). Exercises every
 * conditional branch the mobile layout touches: an order with items and
 * full courier/discount data, a pending item-less Messenger order (the
 * "complete order" form path), and a linked Messenger conversation thread.
 */
class OrderViewsRenderTest extends TestCase
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

    public function test_orders_index_renders_with_mixed_orders_and_filters(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        Order::create([
            'tenant_id' => $tenant->id, 'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim Uddin', 'customer_phone' => '01711223344',
            'status' => 'pending', 'subtotal' => 500, 'total' => 550,
        ]);

        Order::create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'messenger_psid' => 'psid-list-1',
            'customer_name' => 'Fatema Begum', 'customer_phone' => '01899887766',
            'status' => 'processing', 'subtotal' => 1200, 'total' => 1260,
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'CS-1',
            'courier_tracking_code' => 'TRK-1', 'courier_status' => 'in_transit',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders'));
        $response->assertOk();
        $response->assertSee('Karim Uddin');
        $response->assertSee('Fatema Begum');
        $response->assertSee('Messenger'); // messenger-origin indicator on the mobile card

        $filtered = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders?status=processing'));
        $filtered->assertOk();
        $filtered->assertSee('Fatema Begum');
        $filtered->assertDontSee('Karim Uddin');

        $searched = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders?q=01711223344'));
        $searched->assertOk();
        $searched->assertSee('Karim Uddin');
        $searched->assertDontSee('Fatema Begum');
    }

    public function test_order_show_renders_with_items_courier_and_discount(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $order = Order::create([
            'tenant_id' => $tenant->id, 'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim Uddin', 'customer_phone' => '01711223344',
            'customer_address' => 'House 12, Road 5, Dhanmondi, Dhaka',
            'status' => 'processing', 'payment_method' => 'cod',
            'subtotal' => 1000, 'discount' => 100, 'delivery_charge' => 60, 'total' => 960,
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'CS-99',
            'courier_tracking_code' => 'TRK-99', 'courier_status' => 'pending',
        ]);

        OrderItem::create([
            'tenant_id' => $tenant->id, 'order_id' => $order->id,
            'product_name' => 'Cotton Saree', 'variant_name' => 'Red',
            'unit_price' => 500, 'purchase_price' => 300, 'quantity' => 2, 'line_total' => 1000,
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/'.$order->id));

        $response->assertOk();
        $response->assertSee('Cotton Saree');
        $response->assertSee('Red');
        $response->assertSee('Dhanmondi');
        $response->assertSee('CS-99');
        $response->assertSee('TRK-99');
        $response->assertSee('pending'); // courier_status, distinct from order status shown elsewhere
    }

    public function test_order_show_renders_pending_item_less_messenger_order_with_complete_form(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Nusrat', 'phone' => '01911112222']);

        $order = Order::create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'messenger_psid' => 'psid-show-1', 'customer_id' => $customer->id,
            'customer_name' => 'Nusrat', 'customer_phone' => '01911112222',
            'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]);

        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-show-1',
            'customer_name' => 'Nusrat', 'message_text' => 'আপনাদের প্রোডাক্ট আছে?',
            'direction' => 'in', 'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/'.$order->id));

        $response->assertOk();
        $response->assertSee('অর্ডার সম্পূর্ণ করুন'); // complete-order form, since items are empty
        $response->assertSee('মেসেঞ্জার থেকে'); // Messenger-origin badge + section
        $response->assertSee('আপনাদের প্রোডাক্ট আছে?'); // embedded conversation thread
    }

    public function test_order_show_renders_an_image_attachment_in_the_embedded_thread(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $order = Order::create([
            'tenant_id' => $tenant->id, 'source' => 'messenger', 'channel' => 'facebook',
            'messenger_psid' => 'psid-media-1', 'customer_name' => 'Nusrat',
            'customer_phone' => '01911112222', 'status' => 'pending', 'subtotal' => 0, 'total' => 0,
        ]);

        MessengerMessage::create([
            'tenant_id' => $tenant->id, 'sender_psid' => 'psid-media-1',
            'customer_name' => 'Nusrat', 'attachment_url' => 'https://fake-cdn.test/pic.jpg',
            'attachment_type' => 'image', 'direction' => 'in', 'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/'.$order->id));

        $response->assertOk();
        $response->assertSee('fake-cdn.test');
    }

    public function test_order_show_has_a_courier_refresh_status_action_once_sent(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $order = Order::create([
            'tenant_id' => $tenant->id, 'source' => 'web', 'channel' => 'website',
            'customer_name' => 'Karim Uddin', 'customer_phone' => '01711223344',
            'status' => 'processing', 'subtotal' => 500, 'total' => 550,
            'courier_provider' => 'steadfast', 'courier_consignment_id' => 'CS-1',
            'courier_tracking_code' => 'TRK-1', 'courier_status' => 'pending',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/'.$order->id));

        $response->assertOk();
        $response->assertSee('orders/'.$order->id.'/courier/refresh');
    }
}
