<?php

namespace Tests\Feature\AiAgent;

use App\Jobs\ProcessWhatsAppAiAgentMessage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\TenantProductImage;
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

    public function test_an_attachment_only_history_turn_is_preserved_as_a_placeholder_instead_of_silently_dropped(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        // A voice note with no transcription yet — message_text is null,
        // only message_type is set, exactly like a real inbound
        // WhatsApp audio message.
        DB::table('whatsapp_messages')->insert([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => 'wamid.voice-earlier',
            'message_text' => null, 'attachment_url' => 'https://example.test/voice.ogg', 'message_type' => 'audio',
            'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.voice-followup', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.voice-reply']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $contents = array_column($capturedMessages, 'content');
        $this->assertContains('[customer sent a voice message]', $contents);
    }

    public function test_tenant_ai_instructions_reach_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('store_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'ai_custom_instructions',
            'value' => 'ক্যাশ অন ডেলিভারি আছে।', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.instr-1', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.instr-reply-1']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertStringContainsString('ক্যাশ অন ডেলিভারি আছে।', $capturedMessages[0]['content']);
    }

    public function test_real_delivery_charges_reach_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('store_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'delivery_charge_inside_dhaka', 'value' => '80',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.know-1', 'ডেলিভারি চার্জ কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.know-reply-1']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertStringContainsString('Delivery charge inside Dhaka: 80', $capturedMessages[0]['content']);
    }

    // --- Phase 5: product/inventory intelligence ----------------------------------------------

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

    public function test_the_cosrx_snail_cream_scenario_reaches_the_provider_with_real_price_and_stock(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProduct($tenant->id, 'COSRX Snail Cream', 850, purchasePrice: 400);

        DB::table('whatsapp_messages')->insert([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => 'wamid.prod-earlier',
            'message_text' => 'COSRX Snail Cream টা দেখান', 'direction' => 'in', 'message_type' => 'text', 'created_at' => now()->subMinute(),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.prod-followup', 'এইটার দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.prod-reply']]])]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('এটার দাম ৮৫০ টাকা।', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $content = $capturedMessages[0]['content'] ?? '';
        $this->assertStringContainsString('Real product data', $content);
        $this->assertStringContainsString('COSRX Snail Cream', $content);
        $this->assertStringContainsString('850', $content);
        $this->assertStringNotContainsString('400', $content);
    }

    public function test_tenant_as_product_data_never_reaches_tenant_bs_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantB->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);
        $this->makeProduct($tenantA->id, 'Tenant A Secret Product', 999);

        $messageId = $this->seedPendingInboundMessage($tenantB->id, '8801700000000', 'wamid.prod-b', 'Tenant A Secret Product এর দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.prod-reply-b']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenantB->id, $messageId);

        $this->assertStringNotContainsString('Tenant A Secret Product', $capturedMessages[0]['content'] ?? '');
    }

    // --- Phase 6: customer memory --------------------------------------------------------------

    public function test_this_customers_own_order_history_reaches_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('orders')->insert([
            'tenant_id' => $tenant->id, 'order_number' => 'ORD-000009', 'customer_name' => 'Test Customer',
            'customer_phone' => '01700000000', 'customer_address' => 'House 4, Dhaka', 'status' => 'shipped',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.mem-1', 'আমার অর্ডার কোথায়?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.mem-reply-1']]])]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('আপনার অর্ডার শিপড হয়েছে।', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $content = $capturedMessages[0]['content'] ?? '';
        $this->assertStringContainsString('ORD-000009', $content);
        $this->assertStringContainsString('shipped', $content);
        $this->assertStringContainsString('House 4, Dhaka', $content);
    }

    public function test_tenant_as_customer_memory_never_reaches_tenant_bs_ai_call(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->connectPhoneNumber($tenantB->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);

        // Same phone number, but the order belongs to tenant A.
        DB::table('orders')->insert([
            'tenant_id' => $tenantA->id, 'order_number' => 'ORD-SECRET', 'customer_name' => 'Tenant A Customer',
            'customer_phone' => '01700000000', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenantB->id, '8801700000000', 'wamid.mem-b', 'আমার অর্ডার কোথায়?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.mem-reply-b']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenantB->id, $messageId);

        $this->assertStringNotContainsString('ORD-SECRET', $capturedMessages[0]['content'] ?? '');
    }

    // --- Phase 7: human style learning ---------------------------------------------------------

    public function test_real_human_whatsapp_replies_from_this_tenants_history_are_sent_to_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('whatsapp_messages')->insert([
            ['tenant_id' => $tenant->id, 'wa_id' => '8801700000099', 'message_type' => 'text', 'message_text' => 'ডেলিভারি কত দিনে?', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(10)],
            ['tenant_id' => $tenant->id, 'wa_id' => '8801700000099', 'message_type' => 'text', 'message_text' => 'আপু ৩-৪ দিন লাগে 😊', 'direction' => 'out', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(9)],
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.style-1', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.style-reply-1']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertStringContainsString('আপু ৩-৪ দিন লাগে 😊', $capturedMessages[0]['content'] ?? '');
    }

    public function test_ai_generated_whatsapp_replies_are_never_used_as_style_examples(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('whatsapp_messages')->insert([
            ['tenant_id' => $tenant->id, 'wa_id' => '8801700000099', 'message_type' => 'text', 'message_text' => 'ডেলিভারি কত দিনে?', 'direction' => 'in', 'sent_by' => 'human', 'status' => 'contacted', 'created_at' => now()->subMinutes(10)],
            ['tenant_id' => $tenant->id, 'wa_id' => '8801700000099', 'message_type' => 'text', 'message_text' => 'অবশ্যই! ডেলিভারি সাধারণত ৩ থেকে ৪ কার্যদিবসের মধ্যে সম্পন্ন হয়ে থাকে।', 'direction' => 'out', 'sent_by' => 'ai', 'status' => 'contacted', 'created_at' => now()->subMinutes(9)],
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.style-2', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.style-reply-2']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertStringNotContainsString('কার্যদিবসের মধ্যে সম্পন্ন', $capturedMessages[0]['content'] ?? '');
    }

    public function test_the_ai_generated_reply_it_just_sent_is_persisted_with_sent_by_ai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.style-3', 'দাম কত?');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'এটা ৫০০ টাকা।']]]]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.style-reply-3']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertDatabaseHas('whatsapp_messages', [
            'tenant_id' => $tenant->id, 'wamid' => 'wamid.style-reply-3', 'sent_by' => 'ai',
        ]);
    }

    // --- Phase 8: customer emotion --------------------------------------------------------------

    public function test_repeated_unanswered_messages_reach_the_provider_as_a_verified_wait_fact(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('whatsapp_messages')->insert([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'message_type' => 'text',
            'message_text' => 'কেউ আছেন?', 'direction' => 'in', 'status' => 'new', 'created_at' => now()->subMinutes(30),
        ]);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.emo-1', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.emo-reply-1']]])]);

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

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $content = $capturedMessages[0]['content'] ?? '';
        $this->assertStringContainsString('2 messages in a row without a reply', $content);
        $this->assertStringContainsString('30 minute(s)', $content);
    }

    // --- Phase 9: image understanding ----------------------------------------------------------

    /** Inserts an inbound image WhatsAppMessage row (no caption) + its 'pending' job row. */
    protected function seedPendingInboundImageMessage(int $tenantId, string $waId, string $wamid, string $mediaId, ?string $caption = null): int
    {
        $messageId = DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'wamid' => $wamid,
            'message_type' => 'image',
            'message_text' => $caption,
            'attachment_type' => 'image',
            'raw_payload' => json_encode(['image' => ['id' => $mediaId, 'mime_type' => 'image/jpeg']]),
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

    public function test_an_image_with_no_caption_is_dispatched_and_reaches_the_provider_as_a_vision_content_part(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundImageMessage($tenant->id, '8801700000000', 'wamid.img-1', 'media-id-1');

        Http::fake([
            'https://graph.facebook.com/*/media-id-1' => Http::response(['url' => 'https://cdn.example.test/download/media-id-1', 'mime_type' => 'image/jpeg']),
            'https://cdn.example.test/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.img-reply-1']]]),
        ]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('ছবিটা পেয়েছি।', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $userTurn = end($capturedMessages);
        $this->assertIsArray($userTurn['content']);
        $this->assertCount(1, $userTurn['content']);
        $this->assertSame('image_url', $userTurn['content'][0]['type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $userTurn['content'][0]['image_url']['url']);
        $this->assertSame(base64_encode('fake-image-bytes'), substr($userTurn['content'][0]['image_url']['url'], strlen('data:image/jpeg;base64,')));
    }

    public function test_an_image_with_a_caption_sends_both_the_caption_and_the_image(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundImageMessage($tenant->id, '8801700000000', 'wamid.img-2', 'media-id-2', 'এইটা কি আছে?');

        Http::fake([
            'https://graph.facebook.com/*/media-id-2' => Http::response(['url' => 'https://cdn.example.test/download/media-id-2', 'mime_type' => 'image/jpeg']),
            'https://cdn.example.test/*' => Http::response('fake-image-bytes-2', 200, ['Content-Type' => 'image/jpeg']),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.img-reply-2']]]),
        ]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('জি আছে।', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $userTurn = end($capturedMessages);
        $this->assertSame(['type' => 'text', 'text' => 'এইটা কি আছে?'], $userTurn['content'][0]);
        $this->assertSame('image_url', $userTurn['content'][1]['type']);
    }

    public function test_a_media_lookup_failure_falls_back_to_no_reply_rather_than_a_broken_request(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundImageMessage($tenant->id, '8801700000000', 'wamid.img-3', 'media-id-3');

        Http::fake([
            'https://graph.facebook.com/*/media-id-3' => Http::response(['error' => ['message' => 'not found']], 404),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        // No caption and no resolvable image — never charges credit, never
        // sends anything, and the balance stays untouched.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));
        $this->assertEquals(100, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));
    }

    // --- Phase 10: voice/audio understanding -----------------------------------------------------

    /** Inserts an inbound voice WhatsAppMessage row (no caption) + its 'pending' job row. */
    protected function seedPendingVoiceMessage(int $tenantId, string $waId, string $wamid, string $mediaId): int
    {
        $messageId = DB::table('whatsapp_messages')->insertGetId([
            'tenant_id' => $tenantId,
            'wa_id' => $waId,
            'wamid' => $wamid,
            'message_type' => 'audio',
            'message_text' => null,
            'attachment_type' => 'audio',
            'raw_payload' => json_encode(['audio' => ['id' => $mediaId, 'mime_type' => 'audio/ogg']]),
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

    public function test_a_voice_message_with_no_caption_is_transcribed_and_the_transcript_reaches_the_provider(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, '8801700000000', 'wamid.voice-1', 'media-voice-1');

        Http::fake([
            'https://graph.facebook.com/*/media-voice-1' => Http::response(['url' => 'https://cdn.example.test/download/media-voice-1', 'mime_type' => 'audio/ogg']),
            'https://cdn.example.test/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/ogg']),
            '*/audio/transcriptions' => Http::response(['text' => 'এইটার দাম কত?', 'duration' => 8.0]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.voice-reply-1']]]),
        ]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('এটার দাম ৫০০ টাকা।', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $userTurn = end($capturedMessages);
        $this->assertSame('এইটার দাম কত?', $userTurn['content']);
    }

    public function test_the_transcript_is_persisted_onto_the_message_row(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, '8801700000000', 'wamid.voice-2', 'media-voice-2');

        Http::fake([
            'https://graph.facebook.com/*/media-voice-2' => Http::response(['url' => 'https://cdn.example.test/download/media-voice-2', 'mime_type' => 'audio/ogg']),
            'https://cdn.example.test/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/ogg']),
            '*/audio/transcriptions' => Http::response(['text' => 'স্টকে আছে?', 'duration' => 3.0]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.voice-reply-2']]]),
        ]);

        $this->app->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface
        {
            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::success('জি আছে।', 1, 1, 'fake-model');
            }
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertSame('স্টকে আছে?', DB::table('whatsapp_messages')->where('id', $messageId)->value('message_text'));
    }

    public function test_transcription_is_billed_separately_from_the_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.credit_per_minute_transcription' => 1.0]);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, '8801700000000', 'wamid.voice-3', 'media-voice-3');

        Http::fake([
            'https://graph.facebook.com/*/media-voice-3' => Http::response(['url' => 'https://cdn.example.test/download/media-voice-3', 'mime_type' => 'audio/ogg']),
            'https://cdn.example.test/*' => Http::response('fake-audio-bytes', 200, ['Content-Type' => 'audio/ogg']),
            // 30 seconds = 0.5 minutes @ 1.0 credit/minute = 0.5 credit.
            '*/audio/transcriptions' => Http::response(['text' => 'দাম কত?', 'duration' => 30.0]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.voice-reply-3']]]),
        ]);

        $this->app->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface
        {
            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::success('ok', 40, 10, 'fake-model');
            }
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $ledgerTypes = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->pluck('context_type')->all();
        $this->assertContains('whatsapp_voice_transcription', $ledgerTypes);
        $this->assertContains('whatsapp_reply', $ledgerTypes);
    }

    public function test_a_failed_transcription_never_charges_credit_and_never_sends_a_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingVoiceMessage($tenant->id, '8801700000000', 'wamid.voice-4', 'media-voice-4');

        Http::fake([
            'https://graph.facebook.com/*/media-voice-4' => Http::response(['error' => ['message' => 'not found']], 404),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'audio/transcriptions'));
        $this->assertEquals(100, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));
        $this->assertNull(DB::table('whatsapp_messages')->where('id', $messageId)->value('message_text'));
    }

    // --- Phase 12: response humanization ---------------------------------------------------------

    public function test_human_delay_seconds_stays_within_the_configured_bounds(): void
    {
        config(['ai.human_delay_min_seconds' => 3, 'ai.human_delay_max_seconds' => 5]);
        $job = new ProcessWhatsAppAiAgentMessage(1, 1);

        for ($i = 0; $i < 20; $i++) {
            $seconds = $job->humanDelaySeconds();
            $this->assertGreaterThanOrEqual(3, $seconds);
            $this->assertLessThanOrEqual(5, $seconds);
        }
    }

    public function test_the_job_never_actually_sleeps_during_the_test_suite(): void
    {
        config(['ai.openai_api_key' => 'test-key', 'ai.human_delay_min_seconds' => 2, 'ai.human_delay_max_seconds' => 6]);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.humandelay-1', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.humandelay-reply-1']]])]);

        $start = microtime(true);
        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'app()->runningUnitTests() must skip the real sleep(), or every test in this suite would be seconds slower');
    }

    // --- Phase 13: human handoff ------------------------------------------------------------------

    public function test_a_customer_asking_for_a_human_triggers_a_handoff_and_an_honest_final_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.handoff-1', 'আমি একজন মানুষের সাথে কথা বলতে চাই');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.handoff-reply-1']]])]);

        $capturedMessages = null;
        $this->app->bind(AiProviderInterface::class, function () use (&$capturedMessages) {
            return new class($capturedMessages) implements AiProviderInterface
            {
                public function __construct(private &$capturedMessages) {}

                public function chat(array $messages, array $tools = []): AiProviderResponse
                {
                    $this->capturedMessages = $messages;

                    return AiProviderResponse::success('অবশ্যই, আমাদের টিমের একজন এখনই আপনার সাথে কথা বলবে।', 1, 1, 'fake-model');
                }
            };
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertStringContainsString('just asked to speak with a real person', $capturedMessages[0]['content'] ?? '');
        $this->assertSame(1, DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->where('external_id', '8801700000000')->whereNull('resolved_at')->count());
    }

    public function test_a_message_after_handoff_gets_no_further_ai_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('ai_handoffs')->insert([
            'tenant_id' => $tenant->id, 'channel' => 'whatsapp', 'external_id' => '8801700000000',
            'reason' => 'customer_requested', 'created_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.handoff-2', 'আর কতক্ষণ লাগবে?');

        Http::fake();

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    public function test_a_resolved_handoff_no_longer_blocks_the_ai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        DB::table('ai_handoffs')->insert([
            'tenant_id' => $tenant->id, 'channel' => 'whatsapp', 'external_id' => '8801700000000',
            'reason' => 'customer_requested', 'created_at' => now()->subHour(), 'resolved_at' => now(),
        ]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.handoff-3', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.handoff-reply-3']]])]);

        $this->app->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface
        {
            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::success('ok', 1, 1, 'fake-model');
            }
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertDatabaseHas('whatsapp_messages', ['wamid' => 'wamid.handoff-reply-3']);
    }

    /**
     * Phase 18 — mirrors ProcessAiAgentMessageJobTest's identical race test.
     * The isActive() check before generation only proves no handoff existed
     * at that INSTANT; the OpenAI round-trip itself is exactly the kind of
     * gap a customer's "মানুষের সাথে কথা বলতে চাই" or a staff takeover can
     * land in. Simulates that race by inserting the handoff row as a side
     * effect of the fake provider's chat() call — genuinely between this
     * job's first isActive() check and its send call.
     */
    public function test_a_handoff_created_during_generation_stops_the_reply_from_being_sent(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.handoff-race', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.handoff-race-reply']]])]);

        $this->app->bind(AiProviderInterface::class, fn () => new class($tenant) implements AiProviderInterface
        {
            public function __construct(private $tenant) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                DB::table('ai_handoffs')->insert([
                    'tenant_id' => $this->tenant->id, 'channel' => 'whatsapp', 'external_id' => '8801700000000',
                    'reason' => 'customer_requested', 'created_at' => now(),
                ]);

                return AiProviderResponse::success('ok', 1, 1, 'fake-model');
            }
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
        $this->assertSame(
            1,
            DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->where('external_id', '8801700000000')->whereNull('resolved_at')->count()
        );
    }

    // --- Phase 14: platform pause -----------------------------------------------------------------

    public function test_a_platform_paused_tenant_never_gets_an_ai_reply(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('tenants')->where('id', $tenant->id)->update(['ai_paused_at' => now()]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.pause-1', 'দাম কত?');

        Http::fake();

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNothingSent();
    }

    public function test_a_resumed_tenant_gets_ai_replies_again(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        DB::table('tenants')->where('id', $tenant->id)->update(['ai_paused_at' => null]);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000000', 'wamid.pause-2', 'দাম কত?');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.pause-reply-2']]])]);

        $this->app->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface
        {
            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::success('ok', 1, 1, 'fake-model');
            }
        });

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        $this->assertDatabaseHas('whatsapp_messages', ['wamid' => 'wamid.pause-reply-2']);
    }

    // --- "পণ্যের ছবি" (Product Image Memory) ------------------------------------------

    public function test_a_pure_image_request_sends_the_image_with_a_caption_and_never_calls_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $image = $this->makeProductImage($tenant->id, 'COSRX Snail Cream');
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000001', 'wamid.img-1', 'COSRX Snail Cream এর ছবি দেন');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.img-out-1']]])]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));

        $sent = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.img-out-1')->first();
        $this->assertNotNull($sent, 'the stored product image must be sent as a single media message');
        $this->assertSame('image', $sent->attachment_type);
        $this->assertStringContainsString($image->image_path, $sent->attachment_url);
        // WhatsApp supports an image caption in the same send (unlike
        // Messenger's two-call shape) — see ProcessWhatsAppAiAgentMessage::
        // sendProductImageReply()'s docblock.
        $this->assertNotNull($sent->message_text);

        $this->assertSame(100.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'));
    }

    public function test_an_image_request_combined_with_a_real_question_sends_the_image_and_still_answers_via_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProductImage($tenant->id, 'COSRX Snail Cream');
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000002', 'wamid.img-2', 'COSRX Snail Cream এর দাম কত আর ছবি দেন');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'দাম ৮৫০ টাকা।']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10],
            ]),
            '*/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.img-out-2a']]])
                ->push(['messages' => [['id' => 'wamid.img-out-2b']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions'));

        $imageMsg = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.img-out-2a')->first();
        $this->assertNotNull($imageMsg);
        $this->assertSame('image', $imageMsg->attachment_type);
        $this->assertNull($imageMsg->message_text, 'no caption on the send_and_continue path — the following text reply covers it');

        $textMsg = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.img-out-2b')->first();
        $this->assertNotNull($textMsg);
        $this->assertSame('দাম ৮৫০ টাকা।', $textMsg->message_text);
    }

    public function test_an_ambiguous_image_request_sends_a_clarifying_question_and_never_calls_openai(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->makeProductImage($tenant->id, 'Snail Cream');
        $this->makeProductImage($tenant->id, 'Snail Serum');
        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000003', 'wamid.img-3', 'snail এর ছবি দেন');

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.img-out-3']]])]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'chat/completions'));

        $sent = WhatsAppMessage::withoutGlobalScopes()->where('wamid', 'wamid.img-out-3')->first();
        $this->assertNotNull($sent);
        $this->assertNull($sent->attachment_url, 'an ambiguous request must never send a (possibly wrong) image');
        $this->assertStringContainsString('কোন পণ্যটির ছবি চান', $sent->message_text);
    }

    // --- Part 7-9: post-purchase concern / complaint context -------------------------------

    public function test_a_verified_post_purchase_concern_reaches_the_provider_as_a_confirmed_fact(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $order = \App\Models\Order::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'order_number' => 'ORD-000088', 'customer_phone' => '01700000004',
            'customer_name' => 'Test Customer', 'status' => 'delivered',
        ]);
        \App\Models\OrderItem::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'order_id' => $order->id, 'product_name' => 'Bluetooth Speaker']);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000004', 'wamid.pp-1', 'Bluetooth Speaker টা কাজ করছে না');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত শুনে।']]]]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.pp-reply-1']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'Verified')
                && str_contains($content, 'ORD-000088')
                && str_contains($content, 'Bluetooth Speaker');
        });
    }

    public function test_an_unverified_post_purchase_concern_never_claims_the_customer_purchased_anything(): void
    {
        config(['ai.openai_api_key' => 'test-key']);
        $tenant = $this->makeTenant();
        $this->connectPhoneNumber($tenant->id);
        $this->enableAiAgentAndWhatsAppAutoReply($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $messageId = $this->seedPendingInboundMessage($tenant->id, '8801700000005', 'wamid.pp-2', 'এইটা ব্যবহার করার পর সমস্যা হচ্ছে');

        Http::fake([
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'দুঃখিত, কোন পণ্যের কথা বলছেন?']]]]),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.pp-reply-2']]]),
        ]);

        ProcessWhatsAppAiAgentMessage::dispatch($tenant->id, $messageId);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'No verified purchase record was found')
                && ! str_contains($content, 'Verified: this customer\'s order');
        });
    }
}
