<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;

class WhatsAppStatusWebhookTest extends WhatsAppFeatureTestCase
{
    protected int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $this->tenantId = $tenant->id;
        $user = $this->makeUser($tenant->id);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-status', 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'pnid-status', 'is_active' => 1,
        ]);
    }

    protected function postStatus(array $status): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-status',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'pnid-status'],
                        'statuses' => [$status],
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

    protected function makeOutboundMessage(string $wamid): WhatsAppMessage
    {
        return WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'wa_id' => '8801700000000',
            'wamid' => $wamid,
            'message_text' => 'reply from panel',
            'direction' => 'out',
            'status' => 'contacted',
        ]);
    }

    public function test_sent_status_updates_delivery_status_only(): void
    {
        $message = $this->makeOutboundMessage('wamid.status-sent');

        $this->postStatus(['id' => 'wamid.status-sent', 'status' => 'sent', 'timestamp' => (string) time(), 'recipient_id' => '8801700000000'])
            ->assertOk();

        $message->refresh();
        $this->assertSame('sent', $message->delivery_status);
        $this->assertSame('contacted', $message->status, 'the Messenger-style triage status column must never be touched by a delivery status event');
    }

    public function test_status_progresses_sent_then_delivered_then_read(): void
    {
        $message = $this->makeOutboundMessage('wamid.status-progress');

        $this->postStatus(['id' => 'wamid.status-progress', 'status' => 'sent'])->assertOk();
        $this->postStatus(['id' => 'wamid.status-progress', 'status' => 'delivered'])->assertOk();
        $this->postStatus(['id' => 'wamid.status-progress', 'status' => 'read'])->assertOk();

        $this->assertSame('read', $message->refresh()->delivery_status);
    }

    public function test_failed_status_records_error_code_and_message(): void
    {
        $message = $this->makeOutboundMessage('wamid.status-failed');

        $this->postStatus([
            'id' => 'wamid.status-failed', 'status' => 'failed',
            'errors' => [['code' => 131056, 'title' => 'Message failed to send']],
        ])->assertOk();

        $message->refresh();
        $this->assertSame('failed', $message->delivery_status);
        $this->assertSame('131056', (string) $message->error_code);
        $this->assertSame('Message failed to send', $message->error_message);
    }

    public function test_out_of_order_retry_does_not_regress_delivery_status_backwards(): void
    {
        $message = $this->makeOutboundMessage('wamid.status-ooo');

        $this->postStatus(['id' => 'wamid.status-ooo', 'status' => 'read'])->assertOk();
        // A delayed "delivered" retry arriving after "read" was already
        // recorded must not move the row backwards.
        $this->postStatus(['id' => 'wamid.status-ooo', 'status' => 'delivered'])->assertOk();

        $this->assertSame('read', $message->refresh()->delivery_status);
    }

    public function test_status_event_for_an_unknown_wamid_does_not_create_a_fake_message(): void
    {
        $this->postStatus(['id' => 'wamid.never-existed', 'status' => 'delivered'])->assertOk();

        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.never-existed')->count());
    }

    public function test_unrecognized_status_value_is_ignored_without_error(): void
    {
        $message = $this->makeOutboundMessage('wamid.status-weird');

        $this->postStatus(['id' => 'wamid.status-weird', 'status' => 'deleted'])->assertOk();

        $this->assertNull($message->refresh()->delivery_status);
    }
}
