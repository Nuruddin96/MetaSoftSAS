<?php

namespace Tests\Feature\AiAgent;

use App\Models\AiTenantMemory;
use App\Models\Tenant;
use App\Services\AI\AiTenantMemoryService;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiTenantMemoryService — the "Teach Your AI
 * Agent" relevant-Q&A retrieval layer. Matching is a cheap, deterministic
 * keyword-overlap score (see that service's docblock) — these tests cover
 * both correctness (a real paraphrase still matches) and the "never dump
 * every memory into every prompt" token-budget constraint.
 */
class AiTenantMemoryServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function makeMemory(int $tenantId, string $question, string $answer): AiTenantMemory
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        return AiTenantMemory::create(['tenant_id' => $tenantId, 'question' => $question, 'answer' => $answer]);
    }

    public function test_returns_empty_string_when_there_is_no_conversation_text_at_all(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMemory($tenant->id, 'What is the delivery charge inside Dhaka?', '60 BDT.');

        $result = app(AiTenantMemoryService::class)->relevantMemories($tenant->id, []);

        $this->assertSame('', $result);
    }

    public function test_returns_empty_string_when_no_memory_is_saved(): void
    {
        $tenant = $this->makeTenant();

        $result = app(AiTenantMemoryService::class)->relevantMemories($tenant->id, ['delivery charge কত?']);

        $this->assertSame('', $result);
    }

    public function test_matches_a_paraphrased_customer_question_against_a_saved_question(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMemory($tenant->id, 'What is the delivery charge inside Dhaka?', 'Delivery charge inside Dhaka is 60 BDT.');

        // Genuinely different wording/word order — the exact "semantically
        // related, not exact string match" property the task requires.
        $result = app(AiTenantMemoryService::class)->relevantMemories($tenant->id, ['Dhaka delivery charge?']);

        $this->assertStringContainsString('60 BDT', $result);
    }

    public function test_does_not_match_an_unrelated_question(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMemory($tenant->id, 'What is the delivery charge inside Dhaka?', 'Delivery charge inside Dhaka is 60 BDT.');

        $result = app(AiTenantMemoryService::class)->relevantMemories($tenant->id, ['Do you have cash on delivery?']);

        $this->assertSame('', $result);
    }

    public function test_respects_the_configured_max_matched_memories(): void
    {
        config(['ai.memory_match_max' => 1]);
        $tenant = $this->makeTenant();
        $this->makeMemory($tenant->id, 'What is the delivery charge inside Dhaka?', 'Delivery charge inside Dhaka is 60 BDT.');
        $this->makeMemory($tenant->id, 'What is the delivery charge outside Dhaka?', 'Delivery charge outside Dhaka is 120 BDT.');

        $result = app(AiTenantMemoryService::class)->relevantMemories(
            $tenant->id,
            ['delivery charge inside outside Dhaka?']
        );

        $this->assertSame(1, substr_count($result, 'Q:'));
    }

    public function test_never_leaks_tenant_as_memory_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeMemory($tenantA->id, 'What is the delivery charge inside Dhaka?', 'TENANT-A-SECRET-ANSWER-99999');

        $result = app(AiTenantMemoryService::class)->relevantMemories(
            $tenantB->id,
            ['What is the delivery charge inside Dhaka?']
        );

        $this->assertSame('', $result);
        $this->assertStringNotContainsString('TENANT-A-SECRET-ANSWER-99999', $result);
    }

    public function test_a_lookup_failure_degrades_to_empty_string_instead_of_throwing(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMemory($tenant->id, 'What is the delivery charge inside Dhaka?', '60 BDT.');

        Schema::dropIfExists('tenant_ai_memories');

        $result = app(AiTenantMemoryService::class)->relevantMemories($tenant->id, ['delivery charge inside Dhaka?']);

        $this->assertSame('', $result);
    }
}
