<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessWhatsAppAiAgentMessage;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiProviderResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * Covers App\Jobs\ProcessWhatsAppAiAgentMessage's own behavior once
 * dispatched — distinct from WhatsAppAiDispatchTest, which only covers
 * whether the webhook decides to dispatch it at all. Mirrors
 * ProcessAiAgentMessageJobTest.php (Messenger) one-for-one.
 * QUEUE_CONNECTION=sync in phpunit.xml means ::dispatch() below runs the
 * job body inline, so no Queue::fake()/queue:work is needed here — the
 * OpenAI and WhatsApp Send API calls are the things faked instead (NEVER
 * a real OpenAI or WhatsApp call).
 */
class ProcessWhatsAppAiAgentMessageJobTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
    }

    protected function connectPhoneNumber(int $tenantId, string $phoneNumberId = 'pnid-1'): void
    {
        $user = $this->makeUser($tenantId);
        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-'.$phoneNumberId, 'user_access_token' => 'token',
        ]);
        WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => $phoneNumberId, 'is_active' => 1, 'status' => 'active',
        ]);
    }

    /** Inserts an inbound WhatsAppMessage row + its 'pending' ai_whatsapp_message_jobs row, mirroring what the webhook would have written. */
    protected function seedPendingInboundMessage(int $tenantId, string $waId, string $wamid, string $text): int
    {
        $messageId = DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'wamid' => $wamid,
            'message_type' => 'text',
            'message_text' => $text,
            'direction' => 'in',
            'status' => 'new',
            'created_at' => now(),
        ]);

        DB::table('ai_whatsapp_message_jobs')->insert([
            'tenant_id' => $tenantId,
            'whatsapp_message_id' => $messageId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    public function test_a_successful_run_sends_one_reply_via_whatsapp_send_service(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.credit_per_1k_tokens' => 1.0]);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-1', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'দাম ৫০০ টাকা।']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10],
            ]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.out-1']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages'));

        $reply = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.out-1')->first();
        $this->assertNotNull($reply, 'the AI reply must be recorded as an outgoing WhatsAppMessage — via WhatsAppSendService itself, not this job');
        $this->assertSame('out', $reply->direction);
        $this->assertSame('দাম ৫০০ টাকা।', $reply->message_text);

        $this->assertSame(
            'completed',
            DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $messageId)->value('status')
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
        $this->assertSame('whatsapp_reply', $usageRow->context_type);
        $this->assertSame($messageId, $usageRow->context_id);
    }

    public function test_job_re_checks_the_setting_and_stops_if_ai_was_turned_off_after_dispatch(): void
    {
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        // Note: AI is NOT enabled here — simulates the tenant having
        // turned it back off between the webhook dispatching this job and
        // a worker actually picking it up.
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-2', 'আছে?');

        Http::fake(); // any call at all here is a bug — nothing should be requested

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
        $this->assertSame(
            0,
            WhatsAppMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count()
        );
    }

    public function test_openai_failure_does_not_crash_and_sends_no_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-3', 'হ্যালো');

        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['type' => 'server_error', 'message' => 'boom']], 500),
        ]);

        // The whole point: this must not throw.
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages'), 'no reply must ever be sent when OpenAI fails');
        $this->assertSame(
            0,
            WhatsAppMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count()
        );
        $this->assertSame(
            'failed',
            DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $messageId)->value('status')
        );

        // The incoming message itself must be untouched/preserved.
        $incoming = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.in-3')->first();
        $this->assertNotNull($incoming);
        $this->assertSame('হ্যালো', $incoming->message_text);

        // No credit consumed for a call that never actually completed.
        $this->assertEqualsWithDelta(100.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'), 0.0001);
        $this->assertSame(0, DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->count());
    }

    public function test_whatsapp_send_failure_is_handled_safely(): void
    {
        // OpenAI succeeds (credit IS charged — the cost was genuinely
        // incurred), but the WhatsApp Cloud API rejects the send.
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-4', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'দাম ৫০০ টাকা।']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
            '*/messages' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertSame(
            0,
            WhatsAppMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('direction', 'out')->where('delivery_status', 'sent')->count()
        );
        $this->assertSame(
            'failed',
            DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $messageId)->value('status')
        );

        // Credit WAS deducted — the OpenAI cost happened regardless of
        // whether the subsequent WhatsApp send succeeded (same rule
        // ProcessAiAgentMessage's Messenger equivalent already follows).
        $this->assertLessThan(100.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));
    }

    public function test_a_retried_or_duplicate_execution_does_not_send_a_second_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-5', 'স্টকে আছে?');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'জি, স্টকে আছে।']]],
            ]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.out-5']]]),
        ]);

        // First execution — the legitimate one.
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);
        // A second execution for the exact same message — simulates a
        // Laravel-level retry, or the same job being run twice for any
        // other reason. The AiWhatsAppMessageJob::claim() guard (status
        // is already 'completed', not 'pending') must make this a no-op.
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSentCount(2); // exactly one chat/completions + one send, from the first execution only

        $this->assertSame(
            1,
            WhatsAppMessage::withoutGlobalScopes()->where('direction', 'out')->where('tenant_id', $tenant->id)->count(),
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
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 0); // exhausted
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-6', 'দাম কত?');

        Http::fake();

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
        $this->assertSame(
            'failed',
            DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', $messageId)->value('status')
        );
    }

    public function test_whatsapp_auto_reply_off_stops_the_job_even_if_master_switch_is_on(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgent($tenant->id);
        // No enableWhatsAppAutoReply() call — off.
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-7', 'দাম কত?');

        Http::fake();

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    public function test_conversation_history_is_replayed_as_context(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.context_messages' => 10]);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // Prior turns for this same wa_id, oldest first.
        DB::table('whatsapp_messages')->insert([
            ['tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => 'wamid.hist-1', 'message_text' => 'প্রথম মেসেজ', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinutes(5)],
            ['tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => 'wamid.hist-2', 'message_text' => 'উত্তর দিলাম', 'direction' => 'out', 'status' => 'contacted', 'created_at' => now()->subMinutes(4)],
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.in-8', 'নতুন প্রশ্ন');

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('ঠিক আছে', 10, 5, 'fake-model');
                }
            };
        });

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.out-8']]])]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertNotNull($capturedMessages);
        $roles = array_column($capturedMessages, 'content');
        $this->assertContains('প্রথম মেসেজ', $roles);
        $this->assertContains('উত্তর দিলাম', $roles);
        $this->assertContains('নতুন প্রশ্ন', $roles);

        // Role mapping: inbound -> user, outbound -> assistant.
        $historyEntry = collect($capturedMessages)->firstWhere('content', 'প্রথম মেসেজ');
        $this->assertSame('user', $historyEntry['role']);
        $replyEntry = collect($capturedMessages)->firstWhere('content', 'উত্তর দিলাম');
        $this->assertSame('assistant', $replyEntry['role']);
    }

    public function test_tenant_a_conversation_history_never_includes_tenant_bs_messages_for_the_same_wa_id(): void
    {
        // Same customer phone number could plausibly message two
        // different tenants' WhatsApp numbers — their conversations must
        // never bleed into each other's AI context.
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantA->id, 'pnid-a');
        $this->connectPhoneNumber($tenantB->id, 'pnid-b');
        $this->enableAiAgentAndWhatsAppAutoReply($tenantA->id);
        $this->allocateAiCredit($tenantA->id, 100);

        DB::table('whatsapp_messages')->insert([
            'tenant_id' => $tenantB->id, 'wa_id' => '8801700000000', 'wamid' => 'wamid.tenantb-secret',
            'message_text' => 'তেন্যান্ট B এর গোপন কথা', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenantA->id, '8801700000000', 'wamid.tenanta-1', 'হাই');

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('ok', 1, 1, 'fake-model');
                }
            };
        });

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.out-a']]])]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenantA->id, $messageId);

        $contents = array_column($capturedMessages, 'content');
        $this->assertNotContains('তেন্যান্ট B এর গোপন কথা', $contents, "tenant A's AI context must never include tenant B's conversation");
    }
}
