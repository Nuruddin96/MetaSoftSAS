<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessAiAgentMessage;
use App\Models\MessengerMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Jobs\ProcessAiAgentMessage's own behavior once dispatched —
 * distinct from MessengerAiDispatchTest, which only covers whether the
 * webhook decides to dispatch it at all. QUEUE_CONNECTION=sync in
 * phpunit.xml means ::dispatch() below runs the job body inline, so no
 * Queue::fake()/queue:work is needed here — the OpenAI and Messenger Send
 * API calls are the things faked instead (NEVER a real OpenAI call).
 */
class ProcessAiAgentMessageJobTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    /** Inserts an inbound MessengerMessage row + its 'pending' ai_agent_message_jobs row, mirroring what the webhook would have written. */
    protected function seedPendingInboundMessage(int $tenantId, string $psid, string $mid, string $text): int
    {
        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'sender_psid' => $psid,
            'mid' => $mid,
            'message_text' => $text,
            'direction' => 'in',
            'status' => 'new',
            'created_at' => now(),
        ]);

        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenantId,
            'messenger_message_id' => $messageId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    public function test_a_successful_run_sends_one_reply_and_records_it(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.credit_per_1k_tokens' => 1.0]);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-1', 'mid-in-1', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'দাম ৫০০ টাকা।']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
            ]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ai-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'me/messages'));

        $reply = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-ai-reply-1')->first();
        $this->assertNotNull($reply, 'the AI reply must be recorded as an outgoing MessengerMessage');
        $this->assertSame('out', $reply->direction);
        $this->assertSame('দাম ৫০০ টাকা।', $reply->message_text);

        $this->assertSame(
            'completed',
            DB::table('ai_agent_message_jobs')->where('messenger_message_id', $messageId)->value('status')
        );

        // Usage/credit tracking: 50 total tokens @ 1.0 credit/1k = 0.05 credit deducted.
        $this->assertEqualsWithDelta(
            99.95,
            (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'),
            0.0001
        );

        $usageRow = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->first();
        $this->assertNotNull($usageRow, 'a usage ledger row must be recorded for the successful OpenAI call');
        $this->assertSame(40, $usageRow->input_tokens);
        $this->assertSame(10, $usageRow->output_tokens);
        $this->assertSame('messenger_reply', $usageRow->context_type);
        $this->assertSame($messageId, $usageRow->context_id);
    }

    public function test_a_successful_run_marks_the_recorded_reply_as_ai_generated(): void
    {
        // The correctness of AiConversationStyleService's human-only style
        // learning depends entirely on this tag being set correctly here.
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-tag', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-tag', 'mid-in-tag', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দাম ৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ai-reply-tag']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        $reply = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-ai-reply-tag')->first();
        $this->assertNotNull($reply);
        $this->assertSame('ai', $reply->sent_by);
    }

    public function test_real_human_style_examples_from_this_conversations_history_are_sent_to_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-style', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // Prior, unrelated human-written conversation for this same tenant
        // — a different customer entirely, since style examples are
        // tenant-wide, not limited to the current conversation.
        DB::table('messenger_messages')->insert([
            ['tenant_id' => $tenant->id, 'sender_psid' => 'cust-history', 'message_text' => 'ডেলিভারি কত?', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'new', 'created_at' => now()->subMinutes(10)],
            ['tenant_id' => $tenant->id, 'sender_psid' => 'cust-history', 'message_text' => 'ঢাকার ভিতরে ৬০ টাকা।', 'direction' => 'out', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(9)],
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-new', 'mid-in-style', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দাম ৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ai-reply-style']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $systemMessage = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($systemMessage, 'ঢাকার ভিতরে ৬০ টাকা।');
        });
    }

    public function test_the_customers_resolved_name_is_sent_to_the_provider_when_known(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-name', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // An earlier message in this conversation already carried the
        // customer's resolved Facebook name — the current inbound message
        // doesn't need to repeat it for it to still be known.
        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-named', 'mid' => 'mid-earlier',
            'customer_name' => 'Rahim Uddin', 'message_text' => 'হ্যালো', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-named', 'mid-in-name', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দাম ৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ai-reply-name']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $systemMessage = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($systemMessage, 'Rahim Uddin');
        });
    }

    public function test_job_re_checks_the_setting_and_stops_if_ai_was_turned_off_after_dispatch(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-2', ['is_active' => 1]);
        // Note: AI is NOT enabled here — simulates the tenant having
        // turned it back off between the webhook dispatching this job and
        // a worker actually picking it up.
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-2', 'mid-in-2', 'আছে?');

        Http::fake(); // any call at all here is a bug — nothing should be requested

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
        $this->assertSame(
            0,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count()
        );
    }

    public function test_openai_failure_does_not_crash_and_sends_no_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-3', 'mid-in-3', 'হ্যালো');

        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['type' => 'server_error', 'message' => 'boom']], 500),
        ]);

        // The whole point: this must not throw.
        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'me/messages'), 'no reply must ever be sent when OpenAI fails');
        $this->assertSame(
            0,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count()
        );
        $this->assertSame(
            'failed',
            DB::table('ai_agent_message_jobs')->where('messenger_message_id', $messageId)->value('status')
        );

        // No credit consumed for a call that never actually completed —
        // AiCreditService::recordUsage() is only reached after a reply is
        // successfully generated.
        $this->assertEqualsWithDelta(
            100.0,
            (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'),
            0.0001
        );
        $this->assertSame(0, DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->count());
    }

    public function test_a_retried_or_duplicate_execution_does_not_send_a_second_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-4', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-4', 'mid-in-4', 'স্টকে আছে?');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'জি, স্টকে আছে।']]],
            ]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ai-reply-4']),
        ]);

        // First execution — the legitimate one.
        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);
        // A second execution for the exact same message — simulates a
        // Laravel-level retry, or the same job being run twice for any
        // other reason. The AiAgentMessageJob::claim() guard (status is
        // already 'completed', not 'pending') must make this a no-op.
        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSentCount(2); // exactly one chat/completions + one me/messages call, from the first execution only

        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count(),
            'a retried/duplicate job execution must never result in a second outgoing message'
        );

        $this->assertSame(
            1,
            DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->count(),
            'a retried/duplicate job execution must never deduct credit twice'
        );
    }

    public function test_exhausted_credit_stops_before_any_openai_call(): void
    {
        // The central requirement this covers: insufficient credit must
        // prevent the OpenAI call from being made at all, not just fail
        // gracefully after — Http::fake() with no matcher here means ANY
        // request at all fails the assertion below.
        config(['ai.openai_api_key' => 'test-key']);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-5', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 0); // exhausted
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-5', 'mid-in-5', 'দাম কত?');

        Http::fake();

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
        $this->assertSame(
            0,
            MessengerMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count()
        );
        $this->assertSame(
            'failed',
            DB::table('ai_agent_message_jobs')->where('messenger_message_id', $messageId)->value('status')
        );
    }

    public function test_credit_exhausted_between_dispatch_and_processing_still_stops_the_job(): void
    {
        // Simulates the race the docblock describes: credit was fine when
        // the webhook queued this job, but another concurrent message for
        // the same tenant burned the last of it before this worker ran.
        config(['ai.openai_api_key' => 'test-key']);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-6', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 0); // already exhausted by the time this runs
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-6', 'mid-in-6', 'দাম কত?');

        Http::fake();

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    public function test_messenger_auto_reply_off_stops_the_job_even_if_master_switch_is_on(): void
    {
        config(['ai.openai_api_key' => 'test-key']);

        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-7', ['is_active' => 1]);
        $this->enableAiAgent($tenant->id);
        // No enableMessengerAutoReply() call — off.
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-7', 'mid-in-7', 'দাম কত?');

        Http::fake();

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }
}
