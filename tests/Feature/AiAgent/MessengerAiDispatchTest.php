<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessAiAgentMessage;
use App\Models\MessengerMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers MessengerWebhookController::maybeDispatchAiAgent() — whether an
 * inbound Messenger event results in ProcessAiAgentMessage being queued.
 * Deliberately does NOT exercise the job's own behavior (see
 * ProcessAiAgentMessageJobTest) — Queue::fake() here means no job body
 * ever runs, only whether dispatch happened is asserted.
 *
 * Existing Messenger webhook tests (tests/Feature/Facebook/*) are
 * untouched — this class only adds new coverage for the additive AI
 * dispatch hook, never modifies how messages are parsed/stored/deduped.
 */
class MessengerAiDispatchTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
        config(['messenger.app_secret' => 'test-secret']);
    }

    public function test_ai_off_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-off', ['is_active' => 1]);
        // AI Agent left disabled (the default) — no call to enableAiAgent().

        $this->postSignedMessengerWebhook(
            $this->inboundMessengerPayload('page-off', 'cust-1', 'mid-off-1', 'হ্যালো, দাম কত?')
        )->assertOk();

        Queue::assertNotPushed(ProcessAiAgentMessage::class);

        // Confirm existing Messenger behavior is completely unaffected —
        // the message is still stored exactly as before this feature.
        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-off-1')->count()
        );
    }

    public function test_ai_on_dispatches_the_ai_job_for_a_genuine_inbound_text_message(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-on', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $this->postSignedMessengerWebhook(
            $this->inboundMessengerPayload('page-on', 'cust-2', 'mid-on-1', 'হ্যালো, দাম কত?')
        )->assertOk();

        $message = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-on-1')->first();
        $this->assertNotNull($message);

        Queue::assertPushed(
            ProcessAiAgentMessage::class,
            fn (ProcessAiAgentMessage $job) => $job->tenantId === $tenant->id && $job->messengerMessageId === $message->id
        );

        $this->assertSame(
            'pending',
            DB::table('ai_agent_message_jobs')
                ->where('messenger_message_id', $message->id)->value('status')
        );
    }

    public function test_ai_agent_on_but_messenger_auto_reply_off_does_not_dispatch_the_ai_job(): void
    {
        // The master switch alone is not enough — the Messenger-specific
        // toggle must ALSO be on. Simulates a tenant with AI enabled for
        // some other (future) channel but Messenger auto-reply explicitly
        // left off.
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-noauto', ['is_active' => 1]);
        $this->enableAiAgent($tenant->id);
        // No call to enableMessengerAutoReply().
        $this->allocateAiCredit($tenant->id, 100);

        $this->postSignedMessengerWebhook(
            $this->inboundMessengerPayload('page-noauto', 'cust-5', 'mid-noauto-1', 'হ্যালো, দাম কত?')
        )->assertOk();

        Queue::assertNotPushed(ProcessAiAgentMessage::class);

        // The message itself is still stored and visible in the inbox —
        // only the automatic AI reply is skipped.
        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-noauto-1')->count()
        );
    }

    public function test_exhausted_credit_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-nocredit', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 0); // exhausted, not "never allocated"

        $this->postSignedMessengerWebhook(
            $this->inboundMessengerPayload('page-nocredit', 'cust-6', 'mid-nocredit-1', 'হ্যালো, দাম কত?')
        )->assertOk();

        Queue::assertNotPushed(ProcessAiAgentMessage::class);
    }

    public function test_tenant_never_allocated_any_credit_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-neverallocated', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        // No call to allocateAiCredit() at all — no ai_credit_accounts row exists.

        $this->postSignedMessengerWebhook(
            $this->inboundMessengerPayload('page-neverallocated', 'cust-7', 'mid-neveralloc-1', 'হ্যালো, দাম কত?')
        )->assertOk();

        Queue::assertNotPushed(ProcessAiAgentMessage::class);
    }

    public function test_an_outgoing_echo_does_not_dispatch_the_ai_job(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-echo', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // A reply sent from Meta Business Suite / our own Send API echoes
        // back with is_echo=true — this must never be mistaken for a new
        // customer message, whether or not AI is on.
        $this->postSignedMessengerWebhook(
            $this->echoMessengerPayload('page-echo', 'cust-3', 'mid-echo-1', 'জি, ৫০০ টাকা।')
        )->assertOk();

        Queue::assertNotPushed(ProcessAiAgentMessage::class);
    }

    public function test_ai_on_but_attachment_only_message_does_not_dispatch_the_ai_job(): void
    {
        // Image/voice handling is explicitly out of scope for this phase
        // — an attachment-only inbound message (no text) must not trigger
        // AI processing yet.
        Queue::fake();
        // MessengerWebhookController::rehostAttachment() fetches the
        // attachment URL — fake it so this test never makes a real
        // network call regardless of the AI dispatch behavior under test.
        Http::fake(['*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-attach', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-attach',
                'messaging' => [[
                    'sender' => ['id' => 'cust-4'],
                    'recipient' => ['id' => 'page-attach'],
                    'message' => [
                        'mid' => 'mid-attach-1',
                        'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://example.test/photo.jpg']]],
                    ],
                ]],
            ]],
        ];

        $this->postSignedMessengerWebhook($payload)->assertOk();

        Queue::assertNotPushed(ProcessAiAgentMessage::class);
    }
}
