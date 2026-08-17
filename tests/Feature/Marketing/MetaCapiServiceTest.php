<?php

namespace Tests\Feature\Marketing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tenant;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MetaCapiService's Graph API base URL now comes from
 * config('facebook.graph_version') instead of a hard-coded v19.0. The
 * Conversions API /{pixel-id}/events endpoint and payload shape (data[],
 * user_data, custom_data, test_event_code) are unchanged and confirmed
 * compatible against current official Meta documentation; these tests
 * exercise that behavior, not new logic. No DB is touched — Order/OrderItem/
 * Tenant are built in-memory since sendPurchase() only reads attributes.
 */
class MetaCapiServiceTest extends TestCase
{
    protected function makeOrder(): Order
    {
        $order = new Order([
            'fb_event_id' => 'evt-abc-123',
            'customer_phone' => '01712345678',
            'customer_name' => 'Karim Ahmed',
            'order_number' => 'ORD-000001',
            'total' => 1500,
        ]);

        $order->setRelation('items', collect([
            new OrderItem(['sku' => 'SKU-1', 'quantity' => 2, 'unit_price' => 750]),
        ]));

        return $order;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('currentTenant', new Tenant(['subdomain' => 'demo-shop']));
    }

    public function test_sends_to_the_configured_graph_api_version(): void
    {
        config(['facebook.graph_version' => 'v26.0']);
        Http::fake(['*/pixel-123/events*' => Http::response(['events_received' => 1])]);

        (new MetaCapiService('pixel-123', 'capi-token-abc'))->sendPurchase($this->makeOrder());

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            // access_token travels in the POST body, never the URL, so a
            // Guzzle exception message can never leak it.
            return $url === 'https://graph.facebook.com/v26.0/pixel-123/events'
                && $request->data()['access_token'] === 'capi-token-abc';
        });
    }

    public function test_sends_the_correct_event_payload_shape(): void
    {
        Http::fake(['*/pixel-123/events*' => Http::response(['events_received' => 1])]);

        (new MetaCapiService('pixel-123', 'capi-token-abc', 'TEST12345'))->sendPurchase(
            $this->makeOrder(),
            '203.0.113.5',
            'Mozilla/5.0',
            'fbp-cookie-value',
            'fbc-cookie-value'
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $event = $body['data'][0];

            return $event['event_name'] === 'Purchase'
                && $event['event_id'] === 'evt-abc-123'
                && $event['action_source'] === 'website'
                && $event['custom_data']['currency'] === 'BDT'
                && $event['custom_data']['value'] === 1500.0
                && $event['custom_data']['order_id'] === 'ORD-000001'
                && $event['user_data']['client_ip_address'] === '203.0.113.5'
                && $event['user_data']['fbp'] === 'fbp-cookie-value'
                && $body['test_event_code'] === 'TEST12345';
        });
    }

    public function test_hashes_phone_and_name_in_user_data_never_sends_them_plain(): void
    {
        Http::fake(['*/pixel-123/events*' => Http::response(['events_received' => 1])]);

        (new MetaCapiService('pixel-123', 'capi-token-abc'))->sendPurchase($this->makeOrder());

        Http::assertSent(function ($request) {
            $body = $request->data();
            $userData = $body['data'][0]['user_data'];

            return $userData['ph'][0] !== '8801712345678'
                && $userData['fn'][0] !== 'karim ahmed'
                && strlen($userData['ph'][0]) === 64 // sha256 hex length
                && strlen($userData['fn'][0]) === 64;
        });
    }

    public function test_successful_event_returns_a_structured_success_result(): void
    {
        Http::fake(['*/pixel-123/events*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'AbCdEf'])]);

        $result = (new MetaCapiService('pixel-123', 'capi-token-abc'))->sendPurchase($this->makeOrder());

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_status']);
        $this->assertNull($result['error_message']);
    }

    public function test_graph_api_error_response_is_returned_without_throwing(): void
    {
        Http::fake(['*/pixel-123/events*' => Http::response([
            'error' => ['message' => 'Invalid parameter', 'type' => 'GraphMethodException', 'code' => 100],
        ], 400)]);

        $result = (new MetaCapiService('pixel-123', 'capi-token-abc'))->sendPurchase($this->makeOrder());

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['http_status']);
        $this->assertSame(100, $result['error_code']);
        $this->assertSame('Invalid parameter', $result['error_message']);
    }

    /**
     * Phase 1 hardening deliberately logs success/failure (order/tenant/
     * event id, HTTP status, Meta error code/type/message) so failures are
     * observable — the thing that must never happen is the secret itself
     * appearing in a log line, not logging altogether.
     */
    public function test_access_token_is_never_logged(): void
    {
        Log::spy();
        Http::fake(['*/pixel-123/events*' => Http::response(['events_received' => 1])]);

        (new MetaCapiService('pixel-123', 'super-secret-capi-token'))->sendPurchase($this->makeOrder());

        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context = []) {
            return ! str_contains(json_encode([$message, $context]), 'super-secret-capi-token');
        });
    }

    public function test_access_token_is_not_present_in_the_returned_response(): void
    {
        Http::fake(['*/pixel-123/events*' => Http::response(['events_received' => 1])]);

        $result = (new MetaCapiService('pixel-123', 'super-secret-capi-token'))->sendPurchase($this->makeOrder());

        $this->assertStringNotContainsString('super-secret-capi-token', json_encode($result));
    }
}
