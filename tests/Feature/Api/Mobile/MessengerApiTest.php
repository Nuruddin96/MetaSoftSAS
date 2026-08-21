<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\MessengerMessage;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\MessengerController — reuses UnifiedInboxService (the
 * same read-model the web unified inbox uses) and mirrors
 * Tenant\MessengerInboxController's show/reply/status/resume-ai logic
 * exactly. `ai_handoffs` table is intentionally not created here —
 * AiHandoffService::isActive()/resolve() both guard on
 * AiHandoff::tablesReady() and degrade gracefully (false / no-op) when
 * absent, same as production before that migration is imported.
 */
class MessengerApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeConversation(int $tenantId, string $psid, array $attrs = []): MessengerMessage
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        $message = MessengerMessage::create(array_merge([
            'tenant_id' => $tenantId,
            'sender_psid' => $psid,
            'customer_name' => 'Karim',
            'message_text' => 'হ্যালো',
            'direction' => 'in',
            'status' => 'new',
        ], $attrs));
        app()->forgetInstance('currentTenant');

        return $message;
    }

    public function test_index_lists_conversations_via_the_real_unified_inbox_service(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1', ['customer_name' => 'Rahim']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/messenger/conversations')->assertOk();
        $response->assertJsonStructure(['data' => [['psid', 'customer_name', 'status', 'unread_count']], 'has_more']);
        $this->assertSame('psid-1', $response->json('data.0.psid'));
    }

    public function test_index_never_includes_another_tenants_conversations(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->makeConversation($tenantA->id, 'psid-a');
        $this->makeConversation($tenantB->id, 'psid-b');

        Sanctum::actingAs($userA);

        $psids = collect($this->getJson('/api/mobile/v1/messenger/conversations')->json('data'))->pluck('psid');
        $this->assertTrue($psids->contains('psid-a'));
        $this->assertFalse($psids->contains('psid-b'));
    }

    public function test_show_returns_the_full_thread_for_a_known_psid(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1', ['message_text' => 'First message']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/messenger/psid-1')->assertOk();
        $response->assertJsonStructure(['psid', 'customer_name', 'handoff_active', 'messages' => [['id', 'text', 'direction']]]);
        $this->assertSame('First message', $response->json('messages.0.text'));
    }

    public function test_show_404s_for_an_unknown_psid(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/messenger/never-existed')->assertNotFound();
    }

    public function test_reply_sends_via_the_connected_page_and_stores_an_outbound_message(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');
        $this->makeMessengerPage($tenant->id, 'page-1');

        Http::fake(['*/me/messages*' => Http::response(['message_id' => 'mid-reply-1'])]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/messenger/psid-1/reply', ['message' => 'ধন্যবাদ']);

        $response->assertCreated()->assertJsonPath('text', 'ধন্যবাদ')->assertJsonPath('direction', 'out');
        $this->assertDatabaseHas('messenger_messages', ['sender_psid' => 'psid-1', 'direction' => 'out', 'message_text' => 'ধন্যবাদ']);
    }

    public function test_reply_rejects_when_no_page_is_connected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/messenger/psid-1/reply', ['message' => 'Hello'])->assertStatus(422);
    }

    public function test_reply_rejects_an_empty_message(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');
        $this->makeMessengerPage($tenant->id, 'page-1');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/messenger/psid-1/reply', [])->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_update_status_changes_every_message_row_for_that_psid(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        Sanctum::actingAs($user);

        $this->patchJson('/api/mobile/v1/messenger/psid-1/status', ['status' => 'converted'])
            ->assertOk()->assertJsonPath('status', 'converted');
        $this->assertDatabaseHas('messenger_messages', ['sender_psid' => 'psid-1', 'status' => 'converted']);
    }

    public function test_resume_ai_returns_ok_even_without_an_active_handoff_table(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/messenger/psid-1/resume-ai')->assertOk()->assertJsonPath('ok', true);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/messenger/conversations')->assertUnauthorized();
    }
}
