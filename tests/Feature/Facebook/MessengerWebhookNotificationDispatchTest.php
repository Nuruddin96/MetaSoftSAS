<?php

namespace Tests\Feature\Facebook;

use App\Events\CustomerMessageReceived;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * FCM/Web Push notifications task: CustomerMessageReceived +
 * SendNewMessagePush already existed fully built and tested, but nothing
 * ever actually dispatched the event — this pins the real dispatch call
 * added to MessengerWebhookController::handleEvent().
 */
class MessengerWebhookNotificationDispatchTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
        config(['messenger.app_secret' => 'test-secret']);
    }

    public function test_a_genuine_new_inbound_message_dispatches_customer_message_received(): void
    {
        Event::fake([CustomerMessageReceived::class]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1', ['is_active' => 1]);

        $this->postSignedMessengerWebhook(
            $this->inboundMessengerPayload('page-1', 'psid-1', 'mid-1', 'দাম কত?')
        )->assertOk();

        Event::assertDispatched(CustomerMessageReceived::class, fn ($e) => $e->tenantId === $tenant->id
            && $e->channel === 'messenger'
            && $e->externalId === 'psid-1');
    }

    public function test_an_outgoing_echo_does_not_dispatch(): void
    {
        Event::fake([CustomerMessageReceived::class]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-2', ['is_active' => 1]);

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-2',
                'messaging' => [[
                    'sender' => ['id' => 'psid-2'],
                    'message' => ['mid' => 'mid-echo-1', 'text' => 'আমাদের রিপ্লাই', 'is_echo' => true],
                ]],
            ]],
        ];

        $this->postSignedMessengerWebhook($payload)->assertOk();

        Event::assertNotDispatched(CustomerMessageReceived::class);
    }

    public function test_a_retried_duplicate_delivery_does_not_dispatch_again(): void
    {
        Event::fake([CustomerMessageReceived::class]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-3', ['is_active' => 1]);

        $payload = $this->inboundMessengerPayload('page-3', 'psid-3', 'mid-dup-1', 'দাম কত?');

        $this->postSignedMessengerWebhook($payload)->assertOk();
        $this->postSignedMessengerWebhook($payload)->assertOk();

        Event::assertDispatchedTimes(CustomerMessageReceived::class, 1);
    }
}
