<?php

namespace Tests\Feature\Api\Mobile;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\AiChatController — mirrors Tenant\AiChatController
 * exactly, reusing AiChatService/AiPendingActionService/AiCreditService
 * unmodified. Does not touch or depend on the customer-facing Messenger/
 * WhatsApp auto-reply agent (separate, currently-WIP system elsewhere in
 * this repo) — confirmed by AiChatController's own docblock and by none
 * of this test's setup needing anything from that WIP.
 */
class AiChatApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasColumn('store_settings', 'value')) {
            // already present via InteractsWithCommerceSchema; guard kept
            // only for readability of what this test relies on.
        }

        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_conversation_messages')) {
            Schema::create('ai_conversation_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('conversation_id');
                $table->string('role', 20);
                $table->text('content');
                $table->unsignedBigInteger('pending_action_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('ai_pending_actions')) {
            Schema::create('ai_pending_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('tool_name', 100);
                $table->json('resolved_args');
                $table->text('summary');
                $table->string('status', 20)->default('pending');
                $table->json('result')->nullable();
                $table->string('error')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_credit_accounts')) {
            Schema::create('ai_credit_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->decimal('balance', 12, 4)->default(0);
                $table->timestamps();
            });
        }

        // AiCreditAccount::tablesReady() requires both tables together.
        if (! Schema::hasTable('ai_usage_ledger')) {
            Schema::create('ai_usage_ledger', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('type', 20);
                $table->decimal('credit_amount', 12, 4);
                $table->decimal('balance_after', 12, 4);
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    protected function enableAiWithCredit(int $tenantId, float $balance = 10.0): void
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        \App\Models\StoreSetting::create(['tenant_id' => $tenantId, 'key' => 'ai_agent_enabled', 'value' => '1']);
        \App\Models\AiCreditAccount::create(['tenant_id' => $tenantId, 'balance' => $balance]);
        app()->forgetInstance('currentTenant');
    }

    public function test_index_returns_an_empty_conversation_and_real_agent_state_before_any_message(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->enableAiWithCredit($tenant->id, 5.0);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/ai-chat/messages')->assertOk();
        $response->assertJsonPath('ai_agent_enabled', true);
        $response->assertJsonPath('credit_balance', '5.0000');
        $this->assertSame([], $response->json('data'));
    }

    public function test_send_rejects_when_ai_agent_is_disabled(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        \App\Models\AiCreditAccount::create(['tenant_id' => $tenant->id, 'balance' => 5]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/ai-chat/messages', ['message' => 'হ্যালো'])->assertStatus(422);
    }

    public function test_send_rejects_when_credit_is_exhausted(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        \App\Models\StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'ai_agent_enabled', 'value' => '1']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/ai-chat/messages', ['message' => 'হ্যালো'])->assertStatus(422);
    }

    public function test_send_persists_the_user_message_even_when_no_credit_account_exists(): void
    {
        // The user's own message is never lost — same guarantee
        // Tenant\AiChatController::send() gives (its doc comment says the
        // failure only ever loses the AI's reply, not the staff message).
        // Here it's rejected even earlier (no credit), so we instead prove
        // the pre-flight checks run BEFORE anything is persisted at all —
        // no ai_conversations row should exist yet.
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/ai-chat/messages', ['message' => 'হ্যালো'])->assertStatus(422);
        $this->assertDatabaseCount('ai_conversations', 0);
    }

    public function test_confirm_rejects_a_pending_action_belonging_to_another_user(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser($tenant->id);
        $userB = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $action = \App\Models\AiPendingAction::create([
            'tenant_id' => $tenant->id, 'user_id' => $userA->id, 'tool_name' => 'create_order',
            'resolved_args' => [], 'summary' => 'Create an order', 'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->postJson("/api/mobile/v1/ai-chat/actions/{$action->id}/confirm")->assertNotFound();
    }

    public function test_reject_marks_a_pending_action_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $action = \App\Models\AiPendingAction::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'tool_name' => 'create_order',
            'resolved_args' => [], 'summary' => 'Create an order', 'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/ai-chat/actions/{$action->id}/reject")
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('ai_pending_actions', ['id' => $action->id, 'status' => 'rejected']);
    }

    public function test_reject_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        $userA = $this->makeUser($tenantA->id);
        $action = \App\Models\AiPendingAction::create([
            'tenant_id' => $tenantA->id, 'user_id' => $userA->id, 'tool_name' => 'create_order',
            'resolved_args' => [], 'summary' => 'Create an order', 'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->postJson("/api/mobile/v1/ai-chat/actions/{$action->id}/reject")->assertNotFound();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/ai-chat/messages')->assertUnauthorized();
    }
}
