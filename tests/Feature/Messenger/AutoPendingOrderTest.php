<?php

namespace Tests\Feature\Messenger;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers MessengerWebhookController::maybeCreatePendingOrder() — the
 * "phone number is the trigger" flow that turns a Messenger conversation
 * into an existing-Order-module Order with status=pending, reusing the
 * Customer/Order models directly rather than a separate pending-order
 * table. See the implementation plan for the full design rationale.
 */
class AutoPendingOrderTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        config(['messenger.app_secret' => 'test-secret']);
    }

    protected function payload(string $pageId, string $mid, string $psid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $psid],
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    protected function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    public function test_a_message_with_a_valid_phone_number_creates_customer_and_pending_order(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', 'নাম: Rahim, 01712345678'))->assertOk();

        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($customer);
        $this->assertSame('01712345678', $customer->phone);
        $this->assertSame('Rahim', $customer->name);

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status);
        $this->assertSame('messenger', $order->source);
        $this->assertSame('facebook', $order->channel);
        $this->assertSame('psid-1', $order->messenger_psid);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('01712345678', $order->customer_phone);
        $this->assertSame(1, Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_purchase_intent_text_with_no_phone_creates_no_order(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', 'আমি একটা ড্রেস নিতে চাই'))->assertOk();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, Customer::withoutGlobalScopes()->count());
    }

    public function test_customer_info_spread_across_messages_is_merged_onto_the_same_pending_order(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        // 1) purchase intent, no phone yet — no order
        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', 'আমি ওই ড্রেসটা নিতে চাই'))->assertOk();
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        // 2) name only, still no phone — no order
        $this->postWebhook($this->payload('page-1', 'mid-2', 'psid-1', 'আমার নাম Rahim'))->assertOk();
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        // 3) phone arrives — order created, backfilled with the name from message 2
        $this->postWebhook($this->payload('page-1', 'mid-3', 'psid-1', '01712345678'))->assertOk();
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $order = Order::withoutGlobalScopes()->first();
        $this->assertSame('Rahim', $order->customer_name);

        // 4) address arrives on the SAME open conversation — updates the
        // same order in place, does not create a second one
        $this->postWebhook($this->payload('page-1', 'mid-4', 'psid-1', 'ঠিকানা: Mirpur 10, Dhaka'))->assertOk();
        $this->assertSame(1, Order::withoutGlobalScopes()->count(), 'a second message on the same open conversation must update the existing pending order, not create a new one');

        $order->refresh();
        $this->assertStringContainsString('Mirpur 10, Dhaka', (string) $order->customer_address);
    }

    public function test_a_new_order_is_created_after_the_previous_one_left_pending(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', '01712345678'))->assertOk();
        $this->assertSame(1, Order::withoutGlobalScopes()->count());

        // operator completes/confirms the first order
        Order::withoutGlobalScopes()->first()->update(['status' => 'confirmed']);

        // same customer, same conversation, orders again later
        $this->postWebhook($this->payload('page-1', 'mid-2', 'psid-1', '01712345678 আবার লাগবে'))->assertOk();

        $this->assertSame(
            2,
            Order::withoutGlobalScopes()->count(),
            'a repeat phone message after the prior order left "pending" must open a fresh order, not reuse the confirmed one'
        );
    }

    public function test_two_tenants_never_cross_create_customers_or_orders(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantA->id, 'page-a');
        $this->makeMessengerPage($tenantB->id, 'page-b');

        // Same literal PSID string can legitimately occur under different
        // Pages/tenants — Facebook PSIDs are Page-scoped, not global.
        $this->postWebhook($this->payload('page-a', 'mid-a', 'shared-psid', 'নাম: Alice, 01711111111'))->assertOk();
        $this->postWebhook($this->payload('page-b', 'mid-b', 'shared-psid', 'নাম: Bob, 01722222222'))->assertOk();

        $ordersA = Order::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->get();
        $ordersB = Order::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->get();

        $this->assertCount(1, $ordersA);
        $this->assertCount(1, $ordersB);
        $this->assertSame('01711111111', $ordersA->first()->customer_phone);
        $this->assertSame('01722222222', $ordersB->first()->customer_phone);

        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
        $this->assertSame(2, Order::withoutGlobalScopes()->count());
        $this->assertSame(2, Customer::withoutGlobalScopes()->count());
    }

    /**
     * The real-world case this fix targets: a customer never types
     * "Name: ..." (why would they — Facebook already knows who they are),
     * just their phone number. Before this fix, the customer/order name
     * fell straight through to the DEFAULT_CUSTOMER_NAME placeholder
     * ("Messenger Customer") even though handleEvent() had already resolved
     * and cached the real Facebook name via getProfile() on this psid's
     * earlier message.
     */
    public function test_order_uses_the_cached_facebook_profile_name_when_no_name_was_typed(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['first_name' => 'Apo', 'last_name' => 'Rahman']),
        ]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        // First message: no phone, no explicit name — but getProfile()
        // resolves and caches "Apo Rahman" onto this message row.
        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', 'দাম কত?'))->assertOk();
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        // Second message: just the phone number, still no typed name.
        $this->postWebhook($this->payload('page-1', 'mid-2', 'psid-1', '01712345678'))->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('Apo Rahman', $order->customer_name, 'must use the cached Facebook profile name, not the DEFAULT_CUSTOMER_NAME placeholder');
        $this->assertNotSame('Messenger Customer', $order->customer_name);

        $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('Apo Rahman', $customer->name);
    }

    public function test_explicitly_typed_name_still_wins_over_the_facebook_profile_name(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['first_name' => 'Apo', 'last_name' => 'Rahman']),
        ]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', 'নাম: Karim, 01712345678'))->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('Karim', $order->customer_name, 'an explicitly typed name is a deliberate correction and must still take priority');
    }

    public function test_order_falls_back_to_the_placeholder_when_facebook_profile_lookup_also_fails(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'no access']], 400),
        ]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1');

        $this->postWebhook($this->payload('page-1', 'mid-1', 'psid-1', '01712345678'))->assertOk();

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($order, 'order creation must never be blocked by a failed profile lookup');
        $this->assertSame('Messenger Customer', $order->customer_name);
    }
}
