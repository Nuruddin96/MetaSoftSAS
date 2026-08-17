<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiChatService;
use App\Services\AI\AiCreditService;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiProviderResponse;
use App\Services\AI\Tools\AiMutatingTool;
use App\Services\AI\Tools\AiTool;
use App\Services\AI\Tools\AiToolRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiChatService — the panel chat's agentic
 * tool-calling loop. Uses a fake AiProviderInterface (a queue of
 * responses to return on successive calls) so the multi-round-trip
 * tool-calling behavior can be driven deterministically without any real
 * HTTP call.
 */
class AiChatServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function fakeProvider(array $responses): AiProviderInterface
    {
        return new class($responses) implements AiProviderInterface
        {
            protected int $i = 0;

            public array $callsSeen = [];

            public function __construct(protected array $responses) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                $this->callsSeen[] = ['messages' => $messages, 'tools' => $tools];

                return $this->responses[$this->i++] ?? AiProviderResponse::failure();
            }
        };
    }

    protected function spyTool(): AiTool
    {
        return new class implements AiTool
        {
            public array $receivedTenantIds = [];

            public function name(): string
            {
                return 'lookup_orders';
            }

            public function description(): string
            {
                return 'Spy lookup tool.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isMutating(): bool
            {
                return false;
            }

            public function handle(int $tenantId, array $args): array
            {
                $this->receivedTenantIds[] = $tenantId;

                return ['order_number' => $args['order_number'] ?? null, 'status' => 'pending'];
            }
        };
    }

    protected function spyMutatingTool(\Closure $preview): AiMutatingTool
    {
        return new class($preview) implements AiMutatingTool
        {
            public array $previewReceived = [];

            public array $handleReceived = [];

            public function __construct(private \Closure $preview) {}

            public function name(): string
            {
                return 'create_order';
            }

            public function description(): string
            {
                return 'Spy mutating tool.';
            }

            public function parametersSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function isMutating(): bool
            {
                return true;
            }

            public function preview(int $tenantId, array $args): array
            {
                $this->previewReceived[] = ['tenant_id' => $tenantId, 'args' => $args];

                return ($this->preview)($tenantId, $args);
            }

            public function handle(int $tenantId, array $args): array
            {
                $this->handleReceived[] = ['tenant_id' => $tenantId, 'args' => $args];

                return ['success' => true];
            }
        };
    }

    protected function service(AiProviderInterface $provider, AiToolRegistry $registry): AiChatService
    {
        return new AiChatService($provider, $registry, app(AiCreditService::class));
    }

    public function test_returns_null_immediately_without_calling_the_provider_when_credit_is_exhausted(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 0);

        $provider = $this->fakeProvider([AiProviderResponse::success('should never be reached', 1, 1, 'fake')]);

        $result = $this->service($provider, new AiToolRegistry)->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertNull($result);
        $this->assertSame([], $provider->callsSeen);
    }

    public function test_returns_the_reply_directly_when_no_tool_call_is_made(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        $provider = $this->fakeProvider([AiProviderResponse::success('আজকের সেলস ভালো।', 30, 10, 'fake-model')]);

        $result = $this->service($provider, new AiToolRegistry)->reply($tenant->id, 'Shop Basket', [], 'আজকের সেলস কেমন?');

        $this->assertSame(['reply' => 'আজকের সেলস ভালো।'], $result);
        $this->assertCount(1, $provider->callsSeen, 'no tool call means exactly one provider round trip');
    }

    public function test_deducts_credit_for_a_simple_no_tool_call_reply(): void
    {
        config(['ai.credit_per_1k_tokens' => 1.0]);
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        $provider = $this->fakeProvider([AiProviderResponse::success('ok', 30, 10, 'fake-model')]); // 40 tokens = 0.04 credit

        $this->service($provider, new AiToolRegistry)->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertEqualsWithDelta(99.96, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'), 0.0001);
    }

    public function test_executes_a_tool_call_and_feeds_the_result_back_for_a_final_reply(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->spyTool();

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 20, 5, 'fake-model', [
                ['id' => 'call_1', 'name' => 'lookup_orders', 'arguments' => ['order_number' => 'ORD-000123']],
            ]),
            AiProviderResponse::success('অর্ডারটি pending অবস্থায় আছে।', 40, 10, 'fake-model'),
        ]);

        $result = $this->service($provider, new AiToolRegistry([$tool]))
            ->reply($tenant->id, 'Shop', [], 'ORD-000123 এর স্ট্যাটাস কী?');

        $this->assertSame(['reply' => 'অর্ডারটি pending অবস্থায় আছে।'], $result);
        $this->assertCount(2, $provider->callsSeen, 'one round trip for the tool-call request, one for the final reply');
        $this->assertSame([$tenant->id], $tool->receivedTenantIds, 'the tool must receive the real, trusted tenant_id');

        // The second provider call's message history must include the
        // assistant's tool_calls request and the tool's own result —
        // otherwise the model has no idea what the tool returned.
        $secondCallMessages = $provider->callsSeen[1]['messages'];
        $roles = array_column($secondCallMessages, 'role');
        $this->assertContains('tool', $roles);
        $toolMessage = $secondCallMessages[array_search('tool', $roles)];
        $this->assertSame('call_1', $toolMessage['tool_call_id']);
        $this->assertStringContainsString('ORD-000123', $toolMessage['content']);
    }

    public function test_deducts_credit_for_every_round_trip_in_a_tool_calling_loop(): void
    {
        config(['ai.credit_per_1k_tokens' => 1.0]);
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 20, 5, 'fake-model', [ // 25 tokens = 0.025 credit
                ['id' => 'call_1', 'name' => 'lookup_orders', 'arguments' => []],
            ]),
            AiProviderResponse::success('ok', 40, 10, 'fake-model'), // 50 tokens = 0.05 credit
        ]);

        $this->service($provider, new AiToolRegistry([$this->spyTool()]))->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertEqualsWithDelta(99.925, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'), 0.0001);
        $this->assertSame(2, DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->count());
    }

    public function test_stops_and_returns_null_when_the_tool_iteration_cap_is_exceeded(): void
    {
        config(['ai.chat_max_tool_iterations' => 3]);
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        // A pathological provider that ALWAYS wants to call a tool again,
        // never producing a final text reply.
        $alwaysToolCall = AiProviderResponse::success(null, 5, 5, 'fake-model', [
            ['id' => 'call_x', 'name' => 'lookup_orders', 'arguments' => []],
        ]);
        $provider = $this->fakeProvider(array_fill(0, 10, $alwaysToolCall));

        $result = $this->service($provider, new AiToolRegistry([$this->spyTool()]))->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertNull($result);
        $this->assertCount(3, $provider->callsSeen, 'must stop at exactly the configured iteration cap, not loop forever');
    }

    public function test_returns_null_when_credit_runs_out_mid_loop(): void
    {
        config(['ai.credit_per_1k_tokens' => 1.0]);
        $tenant = $this->makeTenant();
        // Enough for the first round trip (25 tokens = 0.025 credit) to be
        // attempted, but it pushes balance negative (0.02 - 0.025 =
        // -0.005) — the loop's next hasCredit() check must then stop it
        // before a second provider call is ever made.
        $this->allocateAiCredit($tenant->id, 0.02);

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 20, 5, 'fake-model', [
                ['id' => 'call_1', 'name' => 'lookup_orders', 'arguments' => []],
            ]),
            // Must never be reached — asserted via callsSeen count below.
            AiProviderResponse::success('should not be reached', 40, 10, 'fake-model'),
        ]);

        $result = $this->service($provider, new AiToolRegistry([$this->spyTool()]))->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertNull($result);
        $this->assertCount(1, $provider->callsSeen, 'must stop before a second round trip once credit goes negative mid-loop');
    }

    public function test_returns_null_when_the_provider_fails(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        $provider = $this->fakeProvider([AiProviderResponse::failure()]);

        $result = $this->service($provider, new AiToolRegistry)->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertNull($result);
    }

    public function test_the_tools_schema_is_passed_to_the_provider(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->spyTool();

        $provider = $this->fakeProvider([AiProviderResponse::success('ok', 1, 1, 'fake-model')]);

        $this->service($provider, new AiToolRegistry([$tool]))->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertSame('lookup_orders', $provider->callsSeen[0]['tools'][0]['function']['name']);
    }

    public function test_a_mutating_tool_call_stops_the_loop_and_returns_the_proposal_summary_instead_of_a_normal_reply(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->spyMutatingTool(fn () => ['summary' => 'নতুন অর্ডার তৈরি হবে — নিশ্চিত করবেন?', 'resolved_args' => ['order_number' => 'X']]);

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 20, 5, 'fake-model', [
                ['id' => 'call_1', 'name' => 'create_order', 'arguments' => ['customer_phone' => '01700000000']],
            ]),
            // Must never be reached — the loop stops at the proposal.
            AiProviderResponse::success('should not be reached', 40, 10, 'fake-model'),
        ]);

        $result = $this->service($provider, new AiToolRegistry([$tool]))->reply($tenant->id, 'Shop', [], 'একটা অর্ডার করো');

        $this->assertSame('নতুন অর্ডার তৈরি হবে — নিশ্চিত করবেন?', $result['reply']);
        $this->assertTrue($result['requires_confirmation']);
        $this->assertSame('create_order', $result['tool_name']);
        $this->assertSame(['order_number' => 'X'], $result['resolved_args']);
        $this->assertCount(1, $provider->callsSeen, 'must stop after the proposal — no second round trip');
    }

    public function test_a_mutating_tool_call_never_reaches_handle_from_the_ai_mediated_loop(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->spyMutatingTool(fn () => ['summary' => 'confirm?', 'resolved_args' => []]);

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 5, 5, 'fake-model', [
                ['id' => 'call_1', 'name' => 'create_order', 'arguments' => []],
            ]),
        ]);

        $this->service($provider, new AiToolRegistry([$tool]))->reply($tenant->id, 'Shop', [], 'একটা অর্ডার করো');

        $this->assertSame([], $tool->handleReceived, 'the AI-mediated loop must never execute a mutation directly — only AiPendingActionService::confirm() may');
    }

    public function test_a_mutating_tool_call_passes_the_trusted_tenant_id_to_preview(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->spyMutatingTool(fn () => ['summary' => 'confirm?', 'resolved_args' => []]);

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 5, 5, 'fake-model', [
                ['id' => 'call_1', 'name' => 'create_order', 'arguments' => ['tenant_id' => 999999]],
            ]),
        ]);

        $this->service($provider, new AiToolRegistry([$tool]))->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertSame($tenant->id, $tool->previewReceived[0]['tenant_id']);
        $this->assertArrayNotHasKey('tenant_id', $tool->previewReceived[0]['args']);
    }

    public function test_a_failed_proposal_feeds_the_error_back_and_the_loop_continues(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->spyMutatingTool(fn () => ['error' => 'প্রোডাক্ট পাওয়া যায়নি।']);

        $provider = $this->fakeProvider([
            AiProviderResponse::success(null, 5, 5, 'fake-model', [
                ['id' => 'call_1', 'name' => 'create_order', 'arguments' => []],
            ]),
            // The model gets a second chance after seeing the error.
            AiProviderResponse::success('দুঃখিত, প্রোডাক্টটি খুঁজে পাইনি।', 10, 5, 'fake-model'),
        ]);

        $result = $this->service($provider, new AiToolRegistry([$tool]))->reply($tenant->id, 'Shop', [], 'হাই');

        $this->assertSame(['reply' => 'দুঃখিত, প্রোডাক্টটি খুঁজে পাইনি।'], $result);
        $this->assertCount(2, $provider->callsSeen);

        $secondCallMessages = $provider->callsSeen[1]['messages'];
        $roles = array_column($secondCallMessages, 'role');
        $toolMessage = $secondCallMessages[array_search('tool', $roles)];
        $this->assertStringContainsString('প্রোডাক্ট পাওয়া যায়নি', $toolMessage['content']);
    }
}
