<?php

namespace Tests\Feature\Tenant;

use App\Models\AiPendingAction;
use App\Models\Tenant;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiProviderResponse;
use App\Services\AI\Tools\AiMutatingTool;
use App\Services\AI\Tools\AiToolRegistry;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers Tenant\AiChatController end-to-end over real HTTP requests — the
 * tenant-authenticated panel chat surface (Phase 4). AiChatServiceTest
 * already covers the agentic tool-calling loop itself in isolation; this
 * file covers auth, tenant isolation, the master toggle/credit gates, and
 * conversation persistence through the real routes.
 */
class AiChatControllerTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function bindFakeProvider(string $reply): void
    {
        $this->app->bind(AiProviderInterface::class, fn () => new class($reply) implements AiProviderInterface
        {
            public function __construct(private string $reply) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::success($this->reply, 10, 5, 'fake-model');
            }
        });
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $tenant = $this->makeTenant();

        $this->get($this->panelUrl($tenant, 'ai-chat'))->assertRedirect();
        $this->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'হাই'])->assertRedirect();
    }

    public function test_authenticated_tenant_can_view_the_chat_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'ai-chat'))
            ->assertOk()
            ->assertSee('Personal Assistant');
    }

    public function test_sending_a_message_when_ai_agent_is_off_does_not_call_the_provider(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        // ai_agent_enabled left off (the default).
        $this->allocateAiCredit($tenant->id, 100);

        // $called is only ever set inside chat() itself — resolving
        // AiChatService (which the controller type-hints, triggering
        // container resolution of its AiProviderInterface dependency
        // regardless of whether ->reply() is ever called) must not be
        // mistaken for the provider actually being invoked.
        $called = false;
        $this->app->bind(AiProviderInterface::class, fn () => new class(function () use (&$called) {
            $called = true;
        }) implements AiProviderInterface
        {

            public function __construct(private \Closure $onCall) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                ($this->onCall)();

                return AiProviderResponse::success('should not be reached', 1, 1, 'fake');
            }
        });

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'হাই'])
            ->assertRedirect();

        $this->assertFalse($called, 'the provider must never be invoked while the AI Agent toggle is off');
        $this->assertSame(0, DB::table('ai_conversation_messages')->where('tenant_id', $tenant->id)->count());
    }

    public function test_sending_a_message_with_no_credit_does_not_call_the_provider(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        // No allocateAiCredit() call at all — never allocated.

        $called = false;
        $this->app->bind(AiProviderInterface::class, fn () => new class(function () use (&$called) {
            $called = true;
        }) implements AiProviderInterface
        {

            public function __construct(private \Closure $onCall) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                ($this->onCall)();

                return AiProviderResponse::success('should not be reached', 1, 1, 'fake');
            }
        });

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'হাই'])
            ->assertRedirect();

        $this->assertFalse($called, 'the provider must never be invoked without allocated credit');
    }

    public function test_a_successful_reply_is_persisted_for_both_turns(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->bindFakeProvider('আজকের সেলস ভালো।');

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'আজকের সেলস কেমন?'])
            ->assertRedirect();

        $messages = DB::table('ai_conversation_messages')->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('আজকের সেলস কেমন?', $messages[0]->content);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('আজকের সেলস ভালো।', $messages[1]->content);
    }

    public function test_the_users_own_message_is_saved_even_if_the_ai_reply_fails(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $this->app->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface
        {
            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::failure();
            }
        });

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'হাই'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $messages = DB::table('ai_conversation_messages')->where('tenant_id', $tenant->id)->get();
        $this->assertCount(1, $messages, "the user's own message must be saved even when the AI fails to reply");
        $this->assertSame('user', $messages[0]->role);
    }

    public function test_tenant_a_never_sees_tenant_bs_conversation(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        app()->instance('currentTenant', $tenantB);
        $this->enableAiAgent($tenantB->id);
        $this->allocateAiCredit($tenantB->id, 100);
        $this->bindFakeProvider('এটি তেন্যান্ট B এর গোপন উত্তর।');
        $this->actingAs($userB, 'tenant')->post($this->panelUrl($tenantB, 'ai-chat/messages'), ['message' => 'হাই']);

        app()->instance('currentTenant', $tenantA);
        $response = $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'ai-chat'));

        $response->assertOk();
        $response->assertDontSee('তেন্যান্ট B এর গোপন উত্তর');
    }

    public function test_conversation_history_is_scoped_per_user_not_shared_across_the_tenant(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser($tenant->id, ['email' => 'a@example.com']);
        $userB = $this->makeUser($tenant->id, ['email' => 'b@example.com']);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->bindFakeProvider('ইউজার A এর জন্য উত্তর।');

        $this->actingAs($userA, 'tenant')->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'হাই']);

        $response = $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenant, 'ai-chat'));

        $response->assertOk();
        $response->assertDontSee('ইউজার A এর জন্য উত্তর');
    }

    public function test_a_blank_message_is_rejected_by_validation(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => ''])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, DB::table('ai_conversation_messages')->where('tenant_id', $tenant->id)->count());
    }

    // --- Phase 5: mutating tools + confirmation system -----------------------------------------------------

    /** A fake mutating tool + a fake provider that immediately calls it — everything needed to reach a real pending-action proposal through the real routes. */
    protected function bindMutatingToolFlow(\Closure $handle): AiMutatingTool
    {
        $tool = new class($handle) implements AiMutatingTool
        {
            public array $handleReceived = [];

            public function __construct(private \Closure $handle) {}

            public function name(): string
            {
                return 'test_mutating_tool';
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
                return ['summary' => 'একটা কাজ করা হবে — নিশ্চিত করবেন?', 'resolved_args' => ['x' => 1]];
            }

            public function handle(int $tenantId, array $args): array
            {
                $this->handleReceived[] = ['tenant_id' => $tenantId, 'args' => $args];

                return ($this->handle)($tenantId, $args);
            }
        };

        $this->app->instance(AiToolRegistry::class, new AiToolRegistry([$tool]));

        $this->app->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface
        {
            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return AiProviderResponse::success(null, 5, 5, 'fake-model', [
                    ['id' => 'call_1', 'name' => 'test_mutating_tool', 'arguments' => []],
                ]);
            }
        });

        return $tool;
    }

    public function test_a_mutating_tool_proposal_is_persisted_with_a_pending_action(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $this->bindMutatingToolFlow(fn () => ['success' => true, 'message' => 'done']);

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'একটা অর্ডার করো'])
            ->assertRedirect();

        $action = AiPendingAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($action);
        $this->assertSame('pending', $action->status);
        $this->assertSame('test_mutating_tool', $action->tool_name);

        $assistantMessage = DB::table('ai_conversation_messages')->where('role', 'assistant')->first();
        $this->assertSame($action->id, $assistantMessage->pending_action_id);
        $this->assertStringContainsString('নিশ্চিত করবেন', $assistantMessage->content);

        // The index page itself must render the confirm/reject buttons
        // for this still-pending action without error.
        $page = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'ai-chat'));
        $page->assertOk();
        $page->assertSee(route('tenant.ai-chat.actions.confirm', $action), false);
        $page->assertSee(route('tenant.ai-chat.actions.reject', $action), false);
    }

    public function test_confirming_a_pending_action_executes_it_and_appends_the_outcome(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->bindMutatingToolFlow(fn () => ['success' => true, 'message' => '✅ কাজ সম্পন্ন হয়েছে']);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'করো']);
        $action = AiPendingAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/actions/'.$action->id.'/confirm'))
            ->assertRedirect();

        $action->refresh();
        $this->assertSame('confirmed', $action->status);
        $this->assertCount(1, $tool->handleReceived, 'handle() must be reached exactly once, only via confirm()');
        $this->assertSame($tenant->id, $tool->handleReceived[0]['tenant_id']);

        $this->assertSame(
            '✅ কাজ সম্পন্ন হয়েছে',
            DB::table('ai_conversation_messages')->where('role', 'assistant')->orderByDesc('id')->value('content'),
            'the outcome must be appended as a new assistant message'
        );
    }

    public function test_rejecting_a_pending_action_never_executes_it(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->bindMutatingToolFlow(fn () => ['success' => true, 'message' => 'should not run']);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'করো']);
        $action = AiPendingAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/actions/'.$action->id.'/reject'))
            ->assertRedirect();

        $action->refresh();
        $this->assertSame('rejected', $action->status);
        $this->assertSame([], $tool->handleReceived);
    }

    public function test_a_different_user_in_the_same_tenant_cannot_confirm_anothers_pending_action(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser($tenant->id, ['email' => 'a@example.com']);
        $userB = $this->makeUser($tenant->id, ['email' => 'b@example.com']);
        app()->instance('currentTenant', $tenant);
        $this->enableAiAgent($tenant->id);
        $this->allocateAiCredit($tenant->id, 100);
        $tool = $this->bindMutatingToolFlow(fn () => ['success' => true, 'message' => 'should not run']);

        $this->actingAs($userA, 'tenant')->post($this->panelUrl($tenant, 'ai-chat/messages'), ['message' => 'করো']);
        $action = AiPendingAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenant, 'ai-chat/actions/'.$action->id.'/confirm'))
            ->assertNotFound();

        $this->assertSame([], $tool->handleReceived);
        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_a_different_tenant_cannot_confirm_anothers_pending_action(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);
        $this->enableAiAgent($tenantA->id);
        $this->allocateAiCredit($tenantA->id, 100);

        app()->instance('currentTenant', $tenantA);
        $tool = $this->bindMutatingToolFlow(fn () => ['success' => true, 'message' => 'should not run']);
        $this->actingAs($userA, 'tenant')->post($this->panelUrl($tenantA, 'ai-chat/messages'), ['message' => 'করো']);
        $action = AiPendingAction::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->first();

        app()->instance('currentTenant', $tenantB);

        $this->actingAs($userB, 'tenant')
            ->post($this->panelUrl($tenantB, 'ai-chat/actions/'.$action->id.'/confirm'))
            ->assertNotFound();

        $this->assertSame([], $tool->handleReceived);
    }
}
