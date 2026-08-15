<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiPendingActionService;
use App\Services\AI\Tools\AiMutatingTool;
use App\Services\AI\Tools\AiToolRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiPendingActionService — the confirmation
 * lifecycle. Uses a fake AiMutatingTool (not a real one) so this stays
 * focused on the propose/confirm/reject/expiry state machine itself,
 * independent of any specific tool's business logic (covered by
 * Create*ToolTest/CourierActionToolTest instead).
 */
class AiPendingActionServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function fakeMutatingTool(\Closure $handle): AiMutatingTool
    {
        return new class($handle) implements AiMutatingTool
        {
            public array $handleReceived = [];

            public function __construct(private \Closure $handle) {}

            public function name(): string
            {
                return 'fake_mutating_tool';
            }

            public function description(): string
            {
                return '...';
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
                return ['summary' => 'preview', 'resolved_args' => $args];
            }

            public function handle(int $tenantId, array $args): array
            {
                $this->handleReceived = ['tenant_id' => $tenantId, 'args' => $args];

                return ($this->handle)($tenantId, $args);
            }
        };
    }

    protected function service(AiMutatingTool $tool): AiPendingActionService
    {
        return new AiPendingActionService(new AiToolRegistry([$tool]));
    }

    public function test_propose_creates_a_pending_row_with_an_expiry(): void
    {
        config(['ai.pending_action_ttl_minutes' => 15]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'ok']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'কনফার্ম করবেন?', ['x' => 1]);

        $this->assertSame('pending', $action->status);
        $this->assertSame($tenant->id, $action->tenant_id);
        $this->assertSame($user->id, $action->user_id);
        $this->assertSame(['x' => 1], $action->resolved_args);
        $this->assertTrue($action->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));
    }

    public function test_confirm_executes_the_tool_with_the_stored_resolved_args_never_anything_else(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'ok']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', ['order_number' => 'ORD-1']);

        $this->service($tool)->confirm($action);

        $this->assertSame($tenant->id, $tool->handleReceived['tenant_id']);
        $this->assertSame(['order_number' => 'ORD-1'], $tool->handleReceived['args']);
    }

    public function test_confirm_marks_the_action_confirmed_and_stores_the_result_on_success(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'অর্ডার তৈরি হয়েছে']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);
        $result = $this->service($tool)->confirm($action);

        $this->assertTrue($result['success']);
        $this->assertSame('অর্ডার তৈরি হয়েছে', $result['message']);

        $action->refresh();
        $this->assertSame('confirmed', $action->status);
        $this->assertNotNull($action->confirmed_at);
        $this->assertSame(['success' => true, 'message' => 'অর্ডার তৈরি হয়েছে'], $action->result);
    }

    public function test_confirm_marks_the_action_failed_when_the_tools_own_business_rule_blocks_it(): void
    {
        // handle() itself can legitimately report failure (stock ran out,
        // order already sent) without throwing — this must be recorded
        // as 'failed', not silently treated as a success.
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => false, 'message' => 'স্টক নেই']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);
        $result = $this->service($tool)->confirm($action);

        $this->assertFalse($result['success']);
        $action->refresh();
        $this->assertSame('failed', $action->status);
        $this->assertSame('স্টক নেই', $action->error);
    }

    public function test_confirm_refuses_an_action_that_is_not_pending(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'should not run']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);
        $action->update(['status' => 'rejected']);

        $result = $this->service($tool)->confirm($action);

        $this->assertFalse($result['success']);
        $this->assertSame([], $tool->handleReceived, 'an already-decided action must never execute the tool');
    }

    public function test_confirm_refuses_and_expires_an_action_past_its_ttl(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'should not run']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);
        $action->update(['expires_at' => now()->subMinute()]);

        $result = $this->service($tool)->confirm($action);

        $this->assertFalse($result['success']);
        $this->assertSame([], $tool->handleReceived);
        $action->refresh();
        $this->assertSame('expired', $action->status);
    }

    public function test_double_confirming_the_same_action_only_executes_the_tool_once(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $calls = 0;
        $tool = $this->fakeMutatingTool(function () use (&$calls) {
            $calls++;

            return ['success' => true, 'message' => 'ok'];
        });

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);

        $this->service($tool)->confirm($action);
        $this->service($tool)->confirm($action->fresh());

        $this->assertSame(1, $calls, 'a second confirm attempt on an already-confirmed action must never re-execute the mutation');
    }

    public function test_reject_marks_pending_as_rejected_and_never_executes_the_tool(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'should not run']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);
        $this->service($tool)->reject($action);

        $action->refresh();
        $this->assertSame('rejected', $action->status);
        $this->assertSame([], $tool->handleReceived);
    }

    public function test_reject_is_a_no_op_on_an_already_decided_action(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'ok']);

        $action = $this->service($tool)->propose($tenant->id, $user->id, null, 'fake_mutating_tool', 'summary', []);
        $this->service($tool)->confirm($action);
        $confirmedAt = $action->fresh()->confirmed_at;

        $this->service($tool)->reject($action->fresh());

        $this->assertSame('confirmed', $action->fresh()->status, 'rejecting an already-confirmed action must not overwrite its outcome');
        $this->assertEquals($confirmedAt, $action->fresh()->confirmed_at);
    }

    public function test_propose_never_touches_another_tenants_data(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tool = $this->fakeMutatingTool(fn () => ['success' => true, 'message' => 'ok']);

        $action = $this->service($tool)->propose($tenantA->id, $userA->id, null, 'fake_mutating_tool', 'summary', []);

        $this->assertSame(0, DB::table('ai_pending_actions')->where('tenant_id', $tenantB->id)->count());
        $this->assertSame($tenantA->id, $action->tenant_id);
    }
}
