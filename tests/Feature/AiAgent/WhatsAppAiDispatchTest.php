<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessWhatsAppAiAgentMessage;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * Covers WhatsAppWebhookController::maybeDispatchAiAgent() — whether an
 * inbound WhatsApp event results in ProcessWhatsAppAiAgentMessage being
 * queued. Mirrors tests/Feature/AiAgent/MessengerAiDispatchTest.php
 * one-for-one; deliberately does NOT exercise the job's own behavior (see
 * ProcessWhatsAppAiAgentMessageJobTest) — Queue::fake() here means no job
 * body ever runs, only whether dispatch happened is asserted.
 *
 * Existing WhatsApp webhook tests (tests/Feature/WhatsApp/*) are
 * untouched — this class only adds new coverage for the additive AI
 * dispatch hook, never modifies how messages are parsed/stored/deduped.
 */
class WhatsAppAiDispatchTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
        config(['whatsapp.app_secret' => 'test-secret']);
    }

    protected function connectPhoneNumber(int $tenantId, string $phoneNumberId = 'pnid-1', string $wabaId = 'waba-1'): void
    {
        $user = $this->makeUser($tenantId);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'connected_by_user_id' => $user->id,
            'waba_id' => $wabaId, 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId, 'is_active' => 1, 'status' => 'active',
        ]);
    }

    protected function postMessage(array $message, string $wabaId = 'waba-1', string $phoneNumberId = 'pnid-1', string $waId = '8801700000000'): TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $wabaId,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
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

    public function test_ai_off_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        // AI Agent left disabled (the default) — no call to enableAiAgent().
        $this->allocateAiCredit($tenant->id, 100);

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.off-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppAiAgentMessage::class);

        // Confirm existing WhatsApp behavior is completely unaffected —
        // the message is still stored exactly as before this feature.
        $this->assertSame(1, WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.off-1')->count());
    }

    public function test_ai_on_dispatches_the_ai_job_for_a_genuine_inbound_text_message(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.on-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        $message = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.on-1')->first();
        $this->assertNotNull($message);

        Queue::assertPushed(
            ProcessWhatsAppAiAgentMessage::class,
            fn (ProcessWhatsAppAiAgentMessage $job) => $job->tenantId === $tenant->id && $job->whatsAppMessageId === $message->id
        );

        $this->assertSame(
            'pending',
            DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $message->id)->value('status')
        );
    }

    public function test_ai_agent_on_but_whatsapp_auto_reply_off_does_not_dispatch_the_ai_job(): void
    {
        // The master switch alone is not enough — the WhatsApp-specific
        // toggle must ALSO be on.
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgent($tenant->id);
        // No call to enableWhatsAppAutoReply().
        $this->allocateAiCredit($tenant->id, 100);

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.noauto-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppAiAgentMessage::class);
        $this->assertSame(1, WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.noauto-1')->count());
    }

    public function test_exhausted_credit_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 0); // exhausted, not "never allocated"

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.nocredit-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppAiAgentMessage::class);
    }

    public function test_tenant_never_allocated_any_credit_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        // No allocateAiCredit() call at all — no ai_credit_accounts row exists.

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.neveralloc-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ])->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppAiAgentMessage::class);
    }

    public function test_a_media_only_message_does_not_dispatch_the_ai_job(): void
    {
        // Same "text only, Phase 1 scope" rule Messenger's dispatch hook
        // already applies to attachment-only messages.
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.media-1', 'timestamp' => (string) time(),
            'type' => 'image', 'image' => ['id' => 'media-id-1', 'mime_type' => 'image/jpeg'],
        ])->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppAiAgentMessage::class);
    }

    public function test_a_duplicate_webhook_delivery_never_dispatches_a_second_ai_job(): void
    {
        // Meta's at-least-once delivery — the same wamid can arrive twice.
        // The UNIQUE(wamid) race path must skip dispatch entirely, same
        // as MessengerWebhookController's identical $message === null
        // pattern.
        Queue::fake();
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $payload = [
            'from' => '8801700000000', 'id' => 'wamid.dup-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'দাম কত?'],
        ];

        $this->postMessage($payload)->assertOk();
        $this->postMessage($payload)->assertOk(); // exact same wamid, redelivered

        $this->assertSame(1, WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.dup-1')->count());
        Queue::assertPushed(ProcessWhatsAppAiAgentMessage::class, 1);
    }

    public function test_a_dispatched_job_always_uses_the_phone_number_owner_never_anything_from_the_payload(): void
    {
        // Tenant A owns pnid-a; tenant B owns pnid-b, and has AI off. A
        // webhook delivery for pnid-a must always dispatch under tenant
        // A's id — Meta's own phone_number_id is the only server-side
        // attribution signal (WhatsAppWebhookController::resolvePhoneNumberOwner()),
        // never anything else in the payload body.
        Queue::fake();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantA->id, 'pnid-a', 'waba-a');
        $this->connectPhoneNumber($tenantB->id, 'pnid-b', 'waba-b');
        $this->enableAiAgentAndWhatsAppAutoReply($tenantA->id);
        $this->allocateAiCredit($tenantA->id, 100);
        // Tenant B deliberately left OFF — proves the job never dispatches under B.

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.cross-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'হাই'],
        ], wabaId: 'waba-a', phoneNumberId: 'pnid-a')->assertOk();

        Queue::assertPushed(
            ProcessWhatsAppAiAgentMessage::class,
            fn (ProcessWhatsAppAiAgentMessage $job) => $job->tenantId === $tenantA->id
        );
        Queue::assertNotPushed(
            ProcessWhatsAppAiAgentMessage::class,
            fn (ProcessWhatsAppAiAgentMessage $job) => $job->tenantId === $tenantB->id
        );
    }

    public function test_an_unknown_phone_number_id_never_dispatches_anything(): void
    {
        // No tenant owns this phone_number_id — same "ignore, don't
        // insert tenant-scoped data for an unattributable event" rule the
        // existing webhook already applies to message storage itself.
        Queue::fake();

        $this->postMessage([
            'from' => '8801700000000', 'id' => 'wamid.unknown-1', 'timestamp' => (string) time(),
            'type' => 'text', 'text' => ['body' => 'হ্যালো'],
        ], phoneNumberId: 'unconnected-pnid')->assertOk();

        Queue::assertNotPushed(ProcessWhatsAppAiAgentMessage::class);
        $this->assertSame(0, WhatsAppMessage::withoutGlobalScopes()->count());
    }
}
