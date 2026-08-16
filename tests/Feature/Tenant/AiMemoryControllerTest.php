<?php

namespace Tests\Feature\Tenant;

use App\Models\AiTenantMemory;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Tenant-side "Teach Your AI Agent" Q&A CRUD (App\Http\Controllers\
 * Tenant\AiMemoryController). Isolation is enforced by implicit route
 * binding through App\Traits\BelongsToTenant::resolveRouteBinding() — see
 * that trait's docblock — the same mechanism every other tenant-owned
 * model's routes already rely on.
 */
class AiMemoryControllerTest extends TestCase
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

    public function test_a_tenant_can_save_a_qa_memory(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'ai-memory'), [
            'question' => 'What is the delivery charge inside Dhaka?',
            'answer' => 'Delivery charge inside Dhaka is 60 BDT.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_ai_memories', [
            'tenant_id' => $tenant->id,
            'question' => 'What is the delivery charge inside Dhaka?',
            'answer' => 'Delivery charge inside Dhaka is 60 BDT.',
        ]);
    }

    public function test_question_is_required(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'ai-memory'), [
            'answer' => 'Delivery charge inside Dhaka is 60 BDT.',
        ]);

        $response->assertSessionHasErrors('question');
        $this->assertSame(0, AiTenantMemory::withoutGlobalScopes()->count());
    }

    public function test_answer_is_required(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'ai-memory'), [
            'question' => 'What is the delivery charge inside Dhaka?',
        ]);

        $response->assertSessionHasErrors('answer');
        $this->assertSame(0, AiTenantMemory::withoutGlobalScopes()->count());
    }

    /**
     * The Settings page itself (SettingController::index()'s $aiMemories)
     * relies on the exact same BelongsToTenant global scope exercised
     * here directly — not re-rendering the full page (which pulls in
     * unrelated courier/marketing/plan tables this schema trait doesn't
     * stub), same "test the mechanism, not the whole page" approach
     * AiProductKnowledgeServiceTest already takes for its own isolation
     * coverage.
     */
    public function test_a_tenant_can_see_only_their_own_memories(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        $memoryA = AiTenantMemory::create(['tenant_id' => $tenantA->id, 'question' => 'A question', 'answer' => 'A answer']);
        AiTenantMemory::create(['tenant_id' => $tenantB->id, 'question' => 'B question', 'answer' => 'B answer']);

        $visible = AiTenantMemory::orderByDesc('id')->get();

        $this->assertCount(1, $visible);
        $this->assertSame($memoryA->id, $visible->first()->id);
    }

    public function test_a_tenant_can_edit_their_own_memory(): void
    {
        $tenant = $this->makeTenant();
        $memory = AiTenantMemory::create(['tenant_id' => $tenant->id, 'question' => 'Old question', 'answer' => 'Old answer']);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "ai-memory/{$memory->id}"), [
            'question' => 'New question',
            'answer' => 'New answer',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tenant_ai_memories', ['id' => $memory->id, 'question' => 'New question', 'answer' => 'New answer']);
    }

    public function test_a_tenant_can_delete_their_own_memory(): void
    {
        $tenant = $this->makeTenant();
        $memory = AiTenantMemory::create(['tenant_id' => $tenant->id, 'question' => 'Q', 'answer' => 'A']);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, "ai-memory/{$memory->id}"));

        $response->assertRedirect();
        $this->assertDatabaseMissing('tenant_ai_memories', ['id' => $memory->id]);
    }

    public function test_a_tenant_cannot_edit_another_tenants_memory(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $memoryA = AiTenantMemory::create(['tenant_id' => $tenantA->id, 'question' => 'A question', 'answer' => 'A answer']);

        $response = $this->actingAs($userB, 'tenant')->put($this->panelUrl($tenantB, "ai-memory/{$memoryA->id}"), [
            'question' => 'Hijacked question',
            'answer' => 'Hijacked answer',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('tenant_ai_memories', ['id' => $memoryA->id, 'question' => 'A question']);
    }

    public function test_a_tenant_cannot_delete_another_tenants_memory(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $memoryA = AiTenantMemory::create(['tenant_id' => $tenantA->id, 'question' => 'A question', 'answer' => 'A answer']);

        $response = $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, "ai-memory/{$memoryA->id}"));

        $response->assertNotFound();
        $this->assertDatabaseHas('tenant_ai_memories', ['id' => $memoryA->id]);
    }
}
