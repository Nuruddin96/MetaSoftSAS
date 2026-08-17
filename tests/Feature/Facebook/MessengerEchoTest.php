<?php

namespace Tests\Feature\Facebook;

use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use Illuminate\Support\Facades\Http;

/**
 * Meta sets message.is_echo=true on webhook events for messages sent FROM
 * the Page — whether via our own Send API call (MessengerInboxController::
 * reply()) or by a human agent replying directly in Meta Business Suite.
 * Before this, the webhook never subscribed to/parsed these events, so only
 * panel-sent replies (inserted directly by reply()) ever showed up as
 * direction='out' — anything sent from Meta Business Suite was invisible.
 */
class MessengerEchoTest extends FacebookFeatureTestCase
{
    protected function connectPage(int $tenantId, int $connectionId, string $pageId): FacebookPage
    {
        return FacebookPage::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'facebook_connection_id' => $connectionId,
            'page_id' => $pageId,
            'page_name' => $pageId.' name',
            'page_access_token' => 'token-for-'.$pageId,
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    protected function echoPayload(string $pageId, string $customerPsid, string $mid, string $text): array
    {
        // Meta reverses sender/recipient on an echo: sender.id is the Page,
        // recipient.id is the customer the Page messaged.
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $pageId],
                    'recipient' => ['id' => $customerPsid],
                    'message' => ['is_echo' => true, 'mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    protected function inboundPayload(string $pageId, string $customerPsid, string $mid, string $text): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'messaging' => [[
                    'sender' => ['id' => $customerPsid],
                    'recipient' => ['id' => $pageId],
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];
    }

    protected function postSignedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-secret');

        return $this->call('POST', '/webhook/messenger', [], [], [], $this->transformHeadersToServerVars([
            'X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);
    }

    public function test_an_echo_from_meta_business_suite_is_recorded_as_outgoing(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $page = $this->connectPage($tenant->id, $conn->id, 'echo-page');

        // Customer messages first, then a reply lands via Meta Business
        // Suite (not our panel) — no MessengerMessage row exists for it yet.
        $this->postSignedWebhook($this->inboundPayload('echo-page', 'cust-1', 'mid-in-1', 'আপনাদের প্রোডাক্ট আছে?'))->assertOk();
        $this->postSignedWebhook($this->echoPayload('echo-page', 'cust-1', 'mid-out-1', 'জি, আছে।'))->assertOk();

        $out = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-out-1')->first();

        $this->assertNotNull($out, 'the Business-Suite echo must be recorded');
        $this->assertSame('out', $out->direction);
        $this->assertSame('cust-1', $out->sender_psid, 'sender_psid must be the customer, not the Page, on an echo event');
        $this->assertSame($tenant->id, $out->tenant_id);
        $this->assertSame($page->id, $out->facebook_page_id);

        // Both messages land in the same conversation, in order.
        $thread = MessengerMessage::withoutGlobalScopes()
            ->where('sender_psid', 'cust-1')->orderBy('id')->pluck('direction', 'message_text');

        $this->assertSame(['আপনাদের প্রোডাক্ট আছে?' => 'in', 'জি, আছে।' => 'out'], $thread->toArray());
    }

    public function test_echo_of_a_panel_sent_reply_does_not_duplicate_it(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $this->connectPage($tenant->id, $conn->id, 'panel-page');

        $this->postSignedWebhook($this->inboundPayload('panel-page', 'cust-2', 'mid-in-2', 'দাম কত?'))->assertOk();

        // Reply sent through OUR panel — MessengerApi::sendMessage is mocked
        // to return the same message_id Meta would echo back.
        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-panel-reply-1'])]);

        app()->instance('currentTenant', $tenant);
        $this->actingAs($user, 'tenant')
            ->post('/shop/'.$tenant->subdomain.'/panel/messenger/cust-2/reply', ['message' => '৳১২০০'])
            ->assertRedirect();

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-panel-reply-1')->count(),
            'the panel reply must be recorded with the mid Meta returned'
        );

        // The corresponding message_echoes event for that same send now
        // arrives from Meta — it must NOT create a second row.
        $this->postSignedWebhook($this->echoPayload('panel-page', 'cust-2', 'mid-panel-reply-1', '৳১২০০'))->assertOk();

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-panel-reply-1')->count(),
            'the echo of our own panel reply must not duplicate the row reply() already created'
        );
    }

    public function test_a_retried_echo_webhook_delivery_is_not_duplicated(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $this->connectPage($tenant->id, $conn->id, 'retry-page');

        $payload = $this->echoPayload('retry-page', 'cust-3', 'mid-retry-1', 'স্টকে আছে');

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk(); // simulated Meta retry of the exact same echo event

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-retry-1')->count(),
            'a retried echo delivery must not create a second row'
        );
    }

    public function test_echo_does_not_trigger_pending_order_autocreation(): void
    {
        config(['messenger.app_secret' => 'test-secret']);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $conn = FacebookConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'facebook_user_id' => 'fbu', 'user_access_token' => 'tok',
        ]);
        $this->connectPage($tenant->id, $conn->id, 'order-page');

        // A phone number appearing only in an outgoing echo (e.g. the Page
        // agent typing a number back to the customer for confirmation)
        // must not be treated as customer-provided purchase intent.
        $this->postSignedWebhook($this->echoPayload('order-page', 'cust-4', 'mid-echo-phone', 'আমাদের নাম্বার 01711223344'))->assertOk();

        $this->assertSame(
            0,
            \App\Models\Order::withoutGlobalScopes()->where('messenger_psid', 'cust-4')->count(),
            'an outgoing echo must never trigger pending-order auto-creation'
        );
    }
}
