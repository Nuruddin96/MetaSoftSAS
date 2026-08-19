<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessAiAgentMessage;
use App\Models\MessengerMessage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\TenantProductImage;
use Illuminate\Http\Client\ConnectionException;
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

    /** Mirrors AiProductKnowledgeServiceTest::makeProduct() — see its docblock. */
    protected function makeProduct(int $tenantId, string $name, float $sellingPrice, float $purchasePrice = 200): Product
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        $product = Product::create(['tenant_id' => $tenantId, 'name' => $name, 'is_active' => true]);

        ProductVariant::create([
            'tenant_id' => $tenantId, 'product_id' => $product->id, 'variant_name' => 'Default',
            'selling_price' => $sellingPrice, 'purchase_price' => $purchasePrice,
        ]);

        return $product;
    }

    /** "পণ্যের ছবি" — mirrors makeProduct() above. */
    protected function makeProductImage(int $tenantId, string $productName): TenantProductImage
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return TenantProductImage::create([
            'tenant_id' => $tenantId,
            'product_name' => $productName,
            'image_path' => 'product-image-memory/'.$tenantId.'/test.jpg',
        ]);
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

        Http::assertSentCount(3); // one chat/completions + one typing_on + one actual send, from the first execution only

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

    // --- Phase 2: conversation context ------------------------------------------------------

    public function test_a_product_named_in_an_earlier_message_is_still_available_when_the_customer_asks_a_followup_price_question(): void
    {
        // The exact regression scenario: product name and price question
        // arrive as two separate messages. The AI must be able to see the
        // product name was already given, never lose it between turns.
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-ctx-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-ctx-1', 'mid' => 'mid-ctx-earlier',
            'message_text' => 'COSRX Snail Cream টা দেখান', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-ctx-1', 'mid-ctx-followup', 'এইটার দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'এটার দাম ৮৫০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ctx-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? [];
            $userTurns = array_column(array_filter($messages, fn ($m) => $m['role'] === 'user'), 'content');

            return in_array('COSRX Snail Cream টা দেখান', $userTurns, true)
                && in_array('এইটার দাম কত?', $userTurns, true);
        });
    }

    public function test_a_variant_mentioned_earlier_is_still_available_for_a_followup_price_question(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-ctx-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-ctx-2', 'mid' => 'mid-variant-earlier',
            'message_text' => 'কালো কালারটা লাগবে', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-ctx-2', 'mid-variant-followup', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-variant-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            return in_array('কালো কালারটা লাগবে', $userTurns, true);
        });
    }

    public function test_an_attachment_only_history_turn_is_preserved_as_a_placeholder_instead_of_silently_dropped(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-ctx-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // A photo with no caption — message_text is null, only the
        // attachment fields are populated, exactly like a real inbound
        // image-only Messenger message.
        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-ctx-3', 'mid' => 'mid-photo-earlier',
            'message_text' => null, 'attachment_url' => 'https://example.test/photo.jpg', 'attachment_type' => 'image',
            'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-ctx-3', 'mid-photo-followup', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ছবিটা পেয়েছি, দাম জানাচ্ছি।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-photo-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            return in_array('[customer sent a photo]', $userTurns, true);
        });
    }

    public function test_an_ambiguous_reference_with_no_prior_context_still_only_sends_what_actually_exists(): void
    {
        // Nothing to resolve — no prior product/topic exists in this
        // conversation. The job must not fabricate history; the prompt
        // rules (SystemPromptContentTest) are what govern the model's
        // actual clarifying-question behavior in this case, not this job.
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-ctx-4', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-ctx-4', 'mid-ambiguous', 'এইটার দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'কোন প্রোডাক্টের কথা বলছেন একটু বলবেন?']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-ambiguous-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $userTurns = array_column(array_filter($request->data()['messages'] ?? [], fn ($m) => $m['role'] === 'user'), 'content');

            // Only the current message itself — no invented prior turns.
            return $userTurns === ['এইটার দাম কত?'];
        });
    }

    // --- Phase 3: tenant AI instructions -----------------------------------------------------

    public function test_tenant_ai_instructions_reach_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-instr-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('store_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'ai_custom_instructions',
            'value' => 'ঢাকার ভিতরে delivery charge ৮০ টাকা। কোনো discount নিজে থেকে দিবে না।',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-instr-1', 'mid-instr-1', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-instr-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains(
            $request->data()['messages'][0]['content'] ?? '',
            'ঢাকার ভিতরে delivery charge ৮০ টাকা। কোনো discount নিজে থেকে দিবে না।'
        ));
    }

    public function test_tenant_as_ai_instructions_never_reach_tenant_bs_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-instr-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);

        DB::table('store_settings')->insert([
            'tenant_id' => $tenantA->id, 'key' => 'ai_custom_instructions',
            'value' => 'তেন্যান্ট A এর গোপন ব্যবসায়িক নিয়ম', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Tenant B deliberately has no instructions of its own.
        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'cust-instr-b', 'mid-instr-b', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-instr-reply-b']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains(
            $request->data()['messages'][0]['content'] ?? '',
            'তেন্যান্ট A'
        ));
    }

    public function test_no_instructions_row_at_all_does_not_add_an_empty_instructions_section(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-instr-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        // No store_settings row for ai_custom_instructions at all.
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-instr-2', 'mid-instr-2', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-instr-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains(
            $request->data()['messages'][0]['content'] ?? '',
            "This business's own instructions"
        ));
    }

    // --- Phase 4: tenant business knowledge --------------------------------------------------

    public function test_real_delivery_charges_reach_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-know-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('store_settings')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '80', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $tenant->id, 'key' => 'delivery_charge_outside_dhaka', 'value' => '150', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-know-1', 'mid-know-1', 'ডেলিভারি চার্জ কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ঢাকার ভিতরে ৮০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-know-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'Delivery charge inside Dhaka: 80')
                && str_contains($content, 'Delivery charge outside Dhaka: 150');
        });
    }

    public function test_tenant_as_delivery_charges_never_reach_tenant_bs_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-know-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);

        DB::table('store_settings')->insert([
            'tenant_id' => $tenantA->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '999',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'cust-know-b', 'mid-know-b', 'ডেলিভারি চার্জ কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-know-reply-b']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains($request->data()['messages'][0]['content'] ?? '', '999'));
    }

    // --- Phase 5: product/inventory intelligence ----------------------------------------------

    public function test_the_cosrx_snail_cream_scenario_reaches_the_provider_with_real_price_and_stock(): void
    {
        // The headline regression this whole phase exists to fix: a
        // product named in an earlier message, price asked in a
        // follow-up, must now reach OpenAI as real catalog data in the
        // system prompt — not just as raw conversation text (that part
        // was already covered by
        // test_a_product_named_in_an_earlier_message_is_still_available_when_the_customer_asks_a_followup_price_question
        // above, which predates this real-data lookup).
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-prod-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850, purchasePrice: 400);

        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-prod-1', 'mid' => 'mid-prod-earlier',
            'message_text' => 'COSRX Snail Cream টা দেখান', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-prod-1', 'mid-prod-followup', 'এইটার দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'এটার দাম ৮৫০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-prod-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'Real product data')
                && str_contains($content, 'COSRX Snail Cream')
                && str_contains($content, '850')
                && ! str_contains($content, '400');
        });
    }

    /**
     * The exact scenario reported in production: "Dr Alvin peeling set টা কত?"
     * followed by a bare "দাম কত?" — must resolve from conversation context
     * (real product data + real tenant business knowledge both present),
     * never falling back to "কোন প্রোডাক্টের দাম জানতে চাচ্ছেন?" Mirrors
     * test_the_cosrx_snail_cream_scenario_reaches_the_provider_with_real_price_and_stock,
     * with a tenant business-instructions block present at the same time to
     * prove the two context sources coexist rather than one crowding out
     * the other.
     */
    public function test_dr_alvin_peeling_set_follow_up_resolves_from_context_alongside_tenant_business_knowledge(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-prod-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProduct($tenant->id, 'Dr Alvin Peeling Set', 1200, purchasePrice: 700);

        DB::table('store_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'ai_custom_instructions',
            'value' => 'Original product imported from the Philippines directly by the business.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-prod-2', 'mid' => 'mid-dralvin-earlier',
            'message_text' => 'Dr Alvin peeling set টা কত?', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-prod-2', 'mid-dralvin-followup', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'এটার দাম ১২০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-dralvin-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'Real product data')
                && str_contains($content, 'Dr Alvin Peeling Set')
                && str_contains($content, '1200')
                && str_contains($content, '[TENANT BUSINESS KNOWLEDGE')
                && str_contains($content, 'Philippines')
                && ! str_contains($content, '700');
        });
    }

    public function test_tenant_as_product_data_never_reaches_tenant_bs_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-prod-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);
        $this->makeProduct($tenantA->id, 'Tenant A Secret Product', 999);

        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'cust-prod-b', 'mid-prod-b', 'Tenant A Secret Product এর দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-prod-reply-b']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains($request->data()['messages'][0]['content'] ?? '', 'Tenant A Secret Product'));
    }

    // --- Phase 6: customer memory --------------------------------------------------------------

    public function test_this_customers_own_order_history_reaches_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-mem-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('orders')->insert([
            'tenant_id' => $tenant->id, 'order_number' => 'ORD-000009', 'messenger_psid' => 'cust-mem-1',
            'customer_name' => 'Test Customer', 'customer_phone' => '01700000000',
            'customer_address' => 'House 4, Dhaka', 'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-mem-1', 'mid-mem-1', 'আমার অর্ডার কোথায়?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'আপনার অর্ডার শিপড হয়েছে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-mem-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'ORD-000009')
                && str_contains($content, 'shipped')
                && str_contains($content, 'House 4, Dhaka');
        });
    }

    public function test_tenant_as_customer_memory_never_reaches_tenant_bs_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-mem-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);

        // Same psid value, but the order belongs to tenant A — must never
        // surface on tenant B's call even though the raw psid string matches.
        DB::table('orders')->insert([
            'tenant_id' => $tenantA->id, 'order_number' => 'ORD-SECRET', 'messenger_psid' => 'shared-psid-mem-b',
            'customer_name' => 'Tenant A Customer', 'customer_phone' => '01700000001',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'shared-psid-mem-b', 'mid-mem-b', 'আমার অর্ডার কোথায়?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-mem-reply-b']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains($request->data()['messages'][0]['content'] ?? '', 'ORD-SECRET'));
    }

    // --- Phase 8: customer emotion -------------------------------------------------------------

    public function test_repeated_unanswered_messages_reach_the_provider_as_a_verified_wait_fact(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-emo-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('messenger_messages')->insert([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-emo-1', 'mid' => 'mid-emo-earlier',
            'message_text' => 'কেউ আছেন?', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinutes(30),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-emo-1', 'mid-emo-followup', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত দেরির জন্য, দাম ৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-emo-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, '2 messages in a row without a reply')
                && str_contains($content, '30 minute(s)')
                && str_contains($content, 'not a guess about mood');
        });
    }

    public function test_a_single_first_time_message_never_adds_a_wait_signal(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-emo-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-emo-2', 'mid-emo-2', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-emo-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains($request->data()['messages'][0]['content'] ?? '', 'A verified fact about this conversation'));
    }

    // --- Phase 9: image understanding ----------------------------------------------------------

    public function test_an_image_with_no_caption_is_dispatched_and_reaches_the_provider_as_a_vision_content_part(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-img-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-img-1', 'mid' => 'mid-img-1',
            'message_text' => null, 'attachment_url' => 'https://example.test/storage/messenger/photo.jpg',
            'attachment_type' => 'image', 'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenant->id, 'messenger_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'হ্যাঁ, এটা স্টকে আছে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-img-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? null;

            if (! is_array($messages)) {
                return false;
            }

            $userTurn = end($messages);

            return is_array($userTurn['content'])
                && count($userTurn['content']) === 1
                && $userTurn['content'][0]['type'] === 'image_url'
                && $userTurn['content'][0]['image_url']['url'] === 'https://example.test/storage/messenger/photo.jpg';
        });
    }

    public function test_an_image_with_a_caption_sends_both_the_caption_and_the_image(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-img-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-img-2', 'mid' => 'mid-img-2',
            'message_text' => 'এইটা কি আছে?', 'attachment_url' => 'https://example.test/storage/messenger/photo2.jpg',
            'attachment_type' => 'image', 'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenant->id, 'messenger_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'জি আছে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-img-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? null;

            if (! is_array($messages)) {
                return false;
            }

            $userTurn = end($messages);

            return $userTurn['content'][0] === ['type' => 'text', 'text' => 'এইটা কি আছে?']
                && $userTurn['content'][1]['type'] === 'image_url';
        });
    }

    public function test_a_non_image_non_audio_attachment_with_no_caption_still_never_dispatches(): void
    {
        // Belt-and-suspenders at the job level — the webhook gate already
        // stops this, but the job's own guard must independently agree.
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-img-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-img-3', 'mid' => 'mid-img-3',
            'message_text' => null, 'attachment_url' => 'https://example.test/storage/messenger/video.mp4',
            'attachment_type' => 'video', 'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenant->id, 'messenger_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake();

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    // --- Phase 10: voice/audio understanding ---------------------------------------------------

    protected function seedPendingVoiceMessage(int $tenantId, string $pageId, string $psid, string $mid): int
    {
        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenantId, 'sender_psid' => $psid, 'mid' => $mid,
            'message_text' => null, 'attachment_url' => 'https://example.test/storage/messenger/voice.mp3',
            'attachment_type' => 'audio', 'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenantId, 'messenger_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $messageId;
    }

    public function test_a_voice_message_with_no_caption_is_transcribed_and_the_transcript_reaches_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-voice-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, 'page-voice-1', 'cust-voice-1', 'mid-voice-1');

        Http::fake([
            'https://example.test/storage/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            '*/audio/transcriptions' => Http::response(['text' => 'এইটার দাম কত?', 'duration' => 8.0]),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'এটার দাম ৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-voice-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? null;

            if (! is_array($messages)) {
                return false;
            }

            $userTurn = end($messages);

            return $userTurn['content'] === 'এইটার দাম কত?';
        });
    }

    public function test_the_transcript_is_persisted_onto_the_message_row(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-voice-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, 'page-voice-2', 'cust-voice-2', 'mid-voice-2');

        Http::fake([
            'https://example.test/storage/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            '*/audio/transcriptions' => Http::response(['text' => 'স্টকে আছে?', 'duration' => 3.0]),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'জি আছে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-voice-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertSame('স্টকে আছে?', DB::table('messenger_messages')->where('id', $messageId)->value('message_text'));
    }

    public function test_transcription_is_billed_separately_from_the_reply_and_both_deduct_credit(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.credit_per_1k_tokens' => 1.0, 'ai.credit_per_minute_transcription' => 1.0]);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-voice-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, 'page-voice-3', 'cust-voice-3', 'mid-voice-3');

        Http::fake([
            'https://example.test/storage/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            // 30 seconds = 0.5 minutes @ 1.0 credit/minute = 0.5 credit.
            '*/audio/transcriptions' => Http::response(['text' => 'দাম কত?', 'duration' => 30.0]),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-voice-reply-3']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        $ledgerTypes = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->pluck('context_type')->all();
        $this->assertContains('messenger_voice_transcription', $ledgerTypes);
        $this->assertContains('messenger_reply', $ledgerTypes);

        // 0.5 (transcription) + 0.05 (50 tokens @ 1.0/1k) = 0.55 total.
        $this->assertEqualsWithDelta(99.45, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'), 0.0001);
    }

    public function test_a_failed_transcription_never_charges_credit_and_never_sends_a_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-voice-4', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, 'page-voice-4', 'cust-voice-4', 'mid-voice-4');

        Http::fake([
            'https://example.test/storage/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            '*/audio/transcriptions' => Http::response(['error' => ['message' => 'could not transcribe']], 400),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
        $this->assertEquals(100, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));
        $this->assertNull(DB::table('messenger_messages')->where('id', $messageId)->value('message_text'));
    }

    public function test_a_failed_transcription_triggers_a_customer_specific_handoff(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-voice-handoff', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, 'page-voice-handoff', 'cust-voice-handoff', 'mid-voice-handoff');

        Http::fake([
            'https://example.test/storage/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            '*/audio/transcriptions' => Http::response(['error' => ['message' => 'could not transcribe']], 400),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertDatabaseHas('ai_handoffs', [
            'tenant_id' => $tenant->id, 'channel' => 'messenger', 'external_id' => 'cust-voice-handoff',
            'reason' => 'unsupported_audio',
        ]);

        // A second inbound message for the SAME customer must not
        // auto-reply either (isActive() gate), but another customer for
        // this same tenant must be completely unaffected.
        $messageId2 = $this->seedPendingInboundMessage($tenant->id, 'cust-voice-handoff', 'mid-voice-handoff-2', 'আছেন?');
        ProcessAiAgentMessage::dispatch($tenant->id, $messageId2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    public function test_an_unresolvable_image_triggers_a_customer_specific_handoff(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-image-handoff', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = DB::table('messenger_messages')->insertGetId([
            'tenant_id' => $tenant->id, 'sender_psid' => 'cust-image-handoff', 'mid' => 'mid-image-handoff',
            'message_text' => null, 'attachment_url' => null, 'attachment_type' => 'image',
            'direction' => 'in', 'status' => 'new', 'created_at' => now(),
        ]);
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenant->id, 'messenger_message_id' => $messageId, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertDatabaseHas('ai_handoffs', [
            'tenant_id' => $tenant->id, 'channel' => 'messenger', 'external_id' => 'cust-image-handoff',
            'reason' => 'unsupported_image',
        ]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    // --- Phase 12: response humanization -------------------------------------------------------

    public function test_human_delay_seconds_stays_within_the_configured_bounds(): void
    {
        config(['ai.human_delay_min_seconds' => 3, 'ai.human_delay_max_seconds' => 5]);
        $job = new ProcessAiAgentMessage(1, 1);

        for ($i = 0; $i < 20; $i++) {
            $seconds = $job->humanDelaySeconds();
            $this->assertGreaterThanOrEqual(3, $seconds);
            $this->assertLessThanOrEqual(5, $seconds);
        }
    }

    public function test_human_delay_seconds_never_goes_negative_even_with_a_misconfigured_min(): void
    {
        config(['ai.human_delay_min_seconds' => -5, 'ai.human_delay_max_seconds' => 6]);
        $job = new ProcessAiAgentMessage(1, 1);

        $this->assertGreaterThanOrEqual(0, $job->humanDelaySeconds());
    }

    public function test_human_delay_seconds_never_lets_min_exceed_max(): void
    {
        // A misconfigured min > max must never crash random_int() with a
        // "min > max" error — max is clamped up to at least min.
        config(['ai.human_delay_min_seconds' => 10, 'ai.human_delay_max_seconds' => 2]);
        $job = new ProcessAiAgentMessage(1, 1);

        $this->assertSame(10, $job->humanDelaySeconds());
    }

    public function test_the_job_never_actually_sleeps_during_the_test_suite(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.human_delay_min_seconds' => 2, 'ai.human_delay_max_seconds' => 6]);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-humandelay-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-humandelay-1', 'mid-humandelay-1', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-humandelay-reply-1']),
        ]);

        $start = microtime(true);
        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'app()->runningUnitTests() must skip the real sleep(), or every test in this suite would be seconds slower');
    }

    public function test_a_typing_indicator_is_sent_before_the_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-humandelay-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-humandelay-2', 'mid-humandelay-2', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-humandelay-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => ($request->data()['sender_action'] ?? null) === 'typing_on');
    }

    public function test_a_failed_typing_indicator_never_blocks_the_actual_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-humandelay-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-humandelay-3', 'mid-humandelay-3', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => function ($request) {
                $body = $request->data();

                if (($body['sender_action'] ?? null) === 'typing_on') {
                    throw new ConnectionException('simulated typing indicator failure');
                }

                return Http::response(['message_id' => 'mid-humandelay-reply-3']);
            },
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertDatabaseHas('messenger_messages', ['mid' => 'mid-humandelay-reply-3']);
    }

    // --- Phase 13: human handoff ----------------------------------------------------------------

    public function test_a_customer_asking_for_a_human_triggers_a_handoff_and_an_honest_final_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-handoff-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-handoff-1', 'mid-handoff-1', 'আমি একজন মানুষের সাথে কথা বলতে চাই');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'অবশ্যই, আমাদের টিমের একজন এখনই আপনার সাথে কথা বলবে।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-handoff-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'] ?? null;

            return is_array($messages) && str_contains($messages[0]['content'] ?? '', 'just asked to speak with a real person');
        });

        $this->assertSame(1, DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->where('external_id', 'cust-handoff-1')->whereNull('resolved_at')->count());
    }

    public function test_a_message_after_handoff_gets_no_further_ai_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-handoff-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('ai_handoffs')->insert([
            'tenant_id' => $tenant->id, 'channel' => 'messenger', 'external_id' => 'cust-handoff-2',
            'reason' => 'customer_requested', 'created_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-handoff-2', 'mid-handoff-2', 'আর কতক্ষণ লাগবে?');

        Http::fake();

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    public function test_a_resolved_handoff_no_longer_blocks_the_ai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-handoff-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('ai_handoffs')->insert([
            'tenant_id' => $tenant->id, 'channel' => 'messenger', 'external_id' => 'cust-handoff-3',
            'reason' => 'customer_requested', 'created_at' => now()->subHour(), 'resolved_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-handoff-3', 'mid-handoff-3', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-handoff-reply-3']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    public function test_tenant_as_handoff_never_blocks_tenant_bs_conversation(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-handoff-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);

        DB::table('ai_handoffs')->insert([
            'tenant_id' => $tenantA->id, 'channel' => 'messenger', 'external_id' => 'shared-psid-handoff',
            'reason' => 'customer_requested', 'created_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'shared-psid-handoff', 'mid-handoff-b', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-handoff-reply-b']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    /**
     * Phase 18 — the isActive() check before generation only proves no
     * handoff existed at that INSTANT; the OpenAI round-trip itself is
     * exactly the kind of gap a customer's "মানুষের সাথে কথা বলতে চাই" or a
     * staff takeover can land in. Simulates that race by inserting the
     * handoff row from inside the chat/completions fake response itself —
     * i.e. genuinely between this job's first isActive() check and its
     * send call, not before the job ever started.
     */
    public function test_a_handoff_created_during_generation_stops_the_reply_from_being_sent(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-handoff-race', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-handoff-race', 'mid-handoff-race', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => function () use ($tenant) {
                DB::table('ai_handoffs')->insert([
                    'tenant_id' => $tenant->id, 'channel' => 'messenger', 'external_id' => 'cust-handoff-race',
                    'reason' => 'customer_requested', 'created_at' => now(),
                ]);

                return Http::response(['choices' => [['message' => ['content' => 'ok']]]]);
            },
            '*/me/messages*' => Http::response(['message_id' => 'mid-handoff-race-reply']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
        // The typing indicator legitimately still fires before the
        // re-check (it's sent right after generation, purely cosmetic —
        // see ProcessAiAgentMessage::humanDelay()'s docblock) and hits the
        // same /me/messages endpoint as an actual reply, distinguished
        // only by payload shape — so assert on the 'message' key itself,
        // not the URL.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/messages') && isset($request->data()['message']));
        $this->assertSame(
            1,
            DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->where('external_id', 'cust-handoff-race')->whereNull('resolved_at')->count()
        );
    }

    // --- Phase 14: platform pause -----------------------------------------------------------------

    public function test_a_platform_paused_tenant_never_gets_an_ai_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-pause-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('tenants')->where('id', $tenant->id)->update(['ai_paused_at' => now()]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-pause-1', 'mid-pause-1', 'দাম কত?');

        Http::fake();

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    public function test_a_resumed_tenant_gets_ai_replies_again(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-pause-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('tenants')->where('id', $tenant->id)->update(['ai_paused_at' => null]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-pause-2', 'mid-pause-2', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-pause-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    public function test_pausing_tenant_a_never_blocks_tenant_bs_ai_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-pause-b', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);
        DB::table('tenants')->where('id', $tenantA->id)->update(['ai_paused_at' => now()]);

        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'cust-pause-b', 'mid-pause-b', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-pause-reply-b']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
    }

    // --- "পণ্যের ছবি" (Product Image Memory) ------------------------------------------

    public function test_a_pure_image_request_sends_the_image_and_a_caption_and_never_calls_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-img-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $image = $this->makeProductImage($tenant->id, 'COSRX Snail Cream');
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-img-1', 'mid-img-1', 'COSRX Snail Cream এর ছবি দেন');

        Http::fake([
            '*/me/messages*' => Http::sequence()
                ->push([]) // sendTypingOn — content irrelevant, this call is best-effort/cosmetic
                ->push(['message_id' => 'mid-img-attachment-1'])
                ->push(['message_id' => 'mid-img-caption-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));

        $attachment = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-attachment-1')->first();
        $this->assertNotNull($attachment, 'the stored product image must be sent as an attachment');
        $this->assertSame('image', $attachment->attachment_type);
        $this->assertStringContainsString($image->image_path, $attachment->attachment_url);

        $caption = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-caption-1')->first();
        $this->assertNotNull($caption, 'a short canned caption must follow the image');
        $this->assertNotNull($caption->message_text);

        // Zero AI cost — no credit was deducted for a deterministically-resolved image.
        $this->assertSame(100.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));

        $this->assertSame(
            'completed',
            DB::table('ai_agent_message_jobs')->where('messenger_message_id', $messageId)->value('status')
        );
    }

    public function test_an_image_request_combined_with_a_real_question_sends_the_image_and_still_answers_via_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-img-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProductImage($tenant->id, 'COSRX Snail Cream');
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-img-2', 'mid-img-2', 'COSRX Snail Cream এর দাম কত আর ছবি দেন');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'দাম ৮৫০ টাকা।']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
            ]),
            '*/me/messages*' => Http::sequence()
                ->push([]) // sendTypingOn (image attachment path)
                ->push(['message_id' => 'mid-img-attachment-2'])
                ->push([]) // sendTypingOn (normal reply path)
                ->push(['message_id' => 'mid-img-textreply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));

        $attachment = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-attachment-2')->first();
        $this->assertNotNull($attachment, 'the image must still be sent alongside the OpenAI-generated answer');
        $this->assertSame('image', $attachment->attachment_type);

        $reply = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-textreply-2')->first();
        $this->assertNotNull($reply);
        $this->assertSame('দাম ৮৫০ টাকা।', $reply->message_text);

        // Exactly one text reply was recorded from OpenAI (plus the image
        // attachment above), never a second, separate canned caption too.
        $this->assertSame(
            1,
            MessengerMessage::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('direction', 'out')->whereNotNull('message_text')->count()
        );
    }

    public function test_an_ambiguous_image_request_sends_a_clarifying_question_and_never_calls_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-img-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProductImage($tenant->id, 'Snail Cream');
        $this->makeProductImage($tenant->id, 'Snail Serum');
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-img-3', 'mid-img-3', 'snail এর ছবি দেন');

        Http::fake([
            '*/me/messages*' => Http::response(['message_id' => 'mid-img-clarify-3']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));

        $reply = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-clarify-3')->first();
        $this->assertNotNull($reply);
        $this->assertNull($reply->attachment_url, 'an ambiguous request must never send a (possibly wrong) image');
        $this->assertStringContainsString('কোন পণ্যটির ছবি চান', $reply->message_text);

        $this->assertSame(100.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));
    }

    public function test_a_product_images_saved_image_is_never_sent_to_a_different_tenant(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMessengerPage($tenantB->id, 'page-img-4', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);
        $this->makeProductImage($tenantA->id, 'Rose Serum');
        $messageId = $this->seedPendingInboundMessage($tenantB->id, 'cust-img-4', 'mid-img-4', 'Rose Serum এর ছবি দেন');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত, নিশ্চিত না।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-img-reply-4']),
        ]);

        ProcessAiAgentMessage::dispatch($tenantB->id, $messageId);

        $reply = MessengerMessage::withoutGlobalScopes()->where('mid', 'mid-img-reply-4')->first();
        $this->assertNotNull($reply);
        $this->assertNull($reply->attachment_url, 'tenant B must never receive tenant A\'s saved product image');
    }

    // --- Part 7-9: post-purchase concern / complaint context -------------------------------

    public function test_a_verified_post_purchase_concern_reaches_the_provider_as_a_confirmed_fact(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-pp-1', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $order = Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'order_number' => 'ORD-000077', 'messenger_psid' => 'cust-pp-1',
            'customer_name' => 'Test Customer', 'customer_phone' => '01700000000', 'status' => 'delivered',
        ]);
        OrderItem::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'product_name' => 'Winter Jacket']);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-pp-1', 'mid-pp-1', 'আপু Winter Jacket টা ধোয়ার পর রং উঠে গেছে');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত শুনে, একটু বিস্তারিত জানাবেন।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-pp-reply-1']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'Verified')
                && str_contains($content, 'ORD-000077')
                && str_contains($content, 'Winter Jacket');
        });
    }

    public function test_an_unverified_post_purchase_concern_never_claims_the_customer_purchased_anything(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-pp-2', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // No order at all for this customer.
        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-pp-2', 'mid-pp-2', 'এইটা ব্যবহার করার পর সমস্যা হচ্ছে');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত, কোন পণ্যের কথা বলছেন?']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-pp-reply-2']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'No verified purchase record was found')
                && ! str_contains($content, 'Verified: this customer\'s order');
        });
    }

    public function test_an_ordinary_message_never_adds_a_post_purchase_concern_section(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->makeMessengerPage($tenant->id, 'page-pp-3', ['is_active' => 1]);
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, 'cust-pp-3', 'mid-pp-3', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '৫০০ টাকা।']]]]),
            '*/me/messages*' => Http::response(['message_id' => 'mid-pp-reply-3']),
        ]);

        ProcessAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => ! str_contains(
            $request->data()['messages'][0]['content'] ?? '',
            'This message may be a complaint'
        ));
    }
}
