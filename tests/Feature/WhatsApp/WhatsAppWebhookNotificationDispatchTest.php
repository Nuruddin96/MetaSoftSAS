<?php

namespace Tests\Feature\WhatsApp;

use App\Events\CustomerMessageReceived;
use App\Models\Tenant;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Support\Facades\Event;

/**
 * FCM/Web Push notifications task: CustomerMessageReceived +
 * SendNewMessagePush already existed fully built and tested, but nothing
 * ever actually dispatched the event — this pins the real dispatch call
 * added to WhatsAppWebhookController::handleIncomingMessage().
 */
class WhatsAppWebhookNotificationDispatchTest extends WhatsAppFeatureTestCase
{
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-secret']);

        $this->tenant = $this->makeTenant();
        $user = $this->makeUser($this->tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-1', 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-1', 'is_active' => 1,
        ]);
    }

    protected function postMessage(array $message, string $waId = '8801700000000'): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '+8801700000000', 'phone_number_id' => 'pnid-1'],
                        'contacts' => [['profile' => ['name' => 'Apo'], 'wa_id' => $waId]],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/whatsapp', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    public function test_a_genuine_new_inbound_message_dispatches_customer_message_received(): void
    {
        Event::fake([CustomerMessageReceived::class]);

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.text-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        Event::assertDispatched(CustomerMessageReceived::class, fn ($e) => $e->tenantId === $this->tenant->id
            && $e->channel === 'whatsapp'
            && $e->externalId === '8801700000000'
            && $e->customerName === 'Apo');
    }

    public function test_a_retried_duplicate_delivery_does_not_dispatch_again(): void
    {
        Event::fake([CustomerMessageReceived::class]);

        $message = [
            'from' => '8801700000000', 'id' => 'wamid.dup-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ];

        $this->postMessage($message)->assertOk();
        $this->postMessage($message)->assertOk();

        Event::assertDispatchedTimes(CustomerMessageReceived::class, 1);
    }

    public function test_a_non_text_attachment_message_still_dispatches_even_though_its_not_ai_dispatchable(): void
    {
        Event::fake([CustomerMessageReceived::class]);

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.doc-1', 'timestamp' => (string) time(),
            'type' => 'document', 'document' => ['id' => 'media-1', 'filename' => 'invoice.pdf'],
        ])->assertOk();

        Event::assertDispatchedTimes(CustomerMessageReceived::class, 1);
    }
}
