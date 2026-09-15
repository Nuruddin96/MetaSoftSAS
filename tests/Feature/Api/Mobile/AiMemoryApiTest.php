<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\AiTenantMemory;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\AiMemoryController — mirrors Tenant\AiMemoryController
 * exactly (see that controller's docblock and its own
 * tests/Feature/Tenant/AiMemoryControllerTest.php for the behavior this
 * mirrors). Uses InteractsWithAiAgentSchema's tenant_ai_memories table,
 * which (like that Tenant-side test) does not include the voice-answer
 * columns — the text-only path, degrading the same way production would
 * before chunk42.sql is imported.
 */
class AiMemoryApiTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAiAgentSchema();
    }

    public function test_index_lists_only_this_tenants_memories_newest_first(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        AiTenantMemory::create(['tenant_id' => $tenant->id, 'question' => 'First', 'answer' => 'A1']);
        AiTenantMemory::create(['tenant_id' => $tenant->id, 'question' => 'Second', 'answer' => 'A2']);
        AiTenantMemory::create(['tenant_id' => $other->id, 'question' => 'Not mine', 'answer' => 'X']);

        $response = $this->getJson('/api/mobile/v1/ai-memory')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Second', $response->json('data.0.question'));
        $this->assertSame('First', $response->json('data.1.question'));
    }

    public function test_store_saves_a_text_qa_memory(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $response = $this->postJson('/api/mobile/v1/ai-memory', [
            'question' => 'What is the delivery charge inside Dhaka?',
            'answer' => 'Delivery charge inside Dhaka is 60 BDT.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('question', 'What is the delivery charge inside Dhaka?')
            ->assertJsonPath('answer_type', 'text')
            ->assertJsonPath('answer_audio_url', null);
        $this->assertDatabaseHas('tenant_ai_memories', ['tenant_id' => $tenant->id, 'question' => 'What is the delivery charge inside Dhaka?']);
    }

    public function test_store_requires_a_question(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/ai-memory', ['answer' => 'A'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');
        $this->assertSame(0, AiTenantMemory::withoutGlobalScopes()->count());
    }

    public function test_store_requires_an_answer(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $this->postJson('/api/mobile/v1/ai-memory', ['question' => 'Q?'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answer');
    }

    public function test_update_edits_an_existing_memory(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $memory = AiTenantMemory::create(['tenant_id' => $tenant->id, 'question' => 'Old', 'answer' => 'Old answer']);

        $response = $this->postJson("/api/mobile/v1/ai-memory/{$memory->id}", [
            'question' => 'New',
            'answer' => 'New answer',
        ]);

        $response->assertOk()->assertJsonPath('question', 'New')->assertJsonPath('answer', 'New answer');
    }

    public function test_update_rejects_another_tenants_memory(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $memory = AiTenantMemory::create(['tenant_id' => $other->id, 'question' => 'A', 'answer' => 'A']);

        $this->postJson("/api/mobile/v1/ai-memory/{$memory->id}", ['question' => 'Hijack', 'answer' => 'Hijack'])
            ->assertStatus(404);
    }

    public function test_destroy_removes_the_memory(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $memory = AiTenantMemory::create(['tenant_id' => $tenant->id, 'question' => 'Q', 'answer' => 'A']);

        $this->deleteJson("/api/mobile/v1/ai-memory/{$memory->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('tenant_ai_memories', ['id' => $memory->id]);
    }

    public function test_destroy_rejects_another_tenants_memory(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);
        app()->instance('currentTenant', $tenant);

        $memory = AiTenantMemory::create(['tenant_id' => $other->id, 'question' => 'A', 'answer' => 'A']);

        $this->deleteJson("/api/mobile/v1/ai-memory/{$memory->id}")->assertStatus(404);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/ai-memory')->assertUnauthorized();
    }
}
