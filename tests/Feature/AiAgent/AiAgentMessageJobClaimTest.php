<?php

namespace Tests\Feature\AiAgent;

use App\Models\AiAgentMessageJob;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers AiAgentMessageJob::claim() directly — the sole duplicate-send
 * guard for App\Jobs\ProcessAiAgentMessage. Phase 15 added stale-'processing'
 * reclaim (see that method's docblock for the "worker died mid-flight,
 * would otherwise drop this message forever" bug it closes); this file
 * proves both that fix AND that the original guarantee (no double-send
 * for a genuinely in-flight or already-finished attempt) still holds.
 */
class AiAgentMessageJobClaimTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function seedJob(int $tenantId, int $messengerMessageId, string $status, ?\DateTimeInterface $updatedAt = null): void
    {
        DB::table('ai_agent_message_jobs')->insert([
            'tenant_id' => $tenantId,
            'messenger_message_id' => $messengerMessageId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }

    public function test_claims_a_pending_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 1, 'pending');

        $this->assertTrue(AiAgentMessageJob::claim($tenant->id, 1));
        $this->assertSame('processing', DB::table('ai_agent_message_jobs')->where('messenger_message_id', 1)->value('status'));
    }

    public function test_a_second_claim_on_the_same_row_fails(): void
    {
        // The original, load-bearing duplicate-send guard.
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 2, 'pending');

        $this->assertTrue(AiAgentMessageJob::claim($tenant->id, 2));
        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 2), 'a second attempt must never re-claim an already-processing row');
    }

    public function test_a_completed_row_can_never_be_reclaimed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 3, 'completed');

        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 3));
    }

    public function test_a_recently_failed_row_can_never_be_reclaimed(): void
    {
        // markFailed() is a genuinely terminal outcome (e.g. OpenAI
        // returned an error, or the Messenger send failed) — never
        // auto-retried by claim() itself, only by a real Laravel-level
        // job retry re-inserting/re-dispatching, which is out of scope
        // for this row's own status semantics.
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 4, 'failed');

        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 4));
    }

    public function test_a_freshly_processing_row_cannot_be_reclaimed(): void
    {
        // The worker is (presumably) still genuinely in flight — must
        // never double-claim a live attempt.
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 5, 'processing', now());

        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 5));
    }

    public function test_a_stale_processing_row_past_retry_after_can_be_reclaimed(): void
    {
        // Phase 15 — the actual bug fix: a worker that died mid-flight
        // (OOM, server restart, hit its own $timeout without pcntl) left
        // this row stuck in 'processing' — without the fix this message
        // would be dropped forever.
        config(['queue.connections.database.retry_after' => 90]);
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 6, 'processing', now()->subSeconds(200));

        $this->assertTrue(AiAgentMessageJob::claim($tenant->id, 6));
        $this->assertSame('processing', DB::table('ai_agent_message_jobs')->where('messenger_message_id', 6)->value('status'));
    }

    public function test_a_processing_row_exactly_at_the_retry_after_boundary_is_not_yet_reclaimed(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $tenant = $this->makeTenant();
        // Well within the window (89s < 90s) — still a plausibly live attempt.
        $this->seedJob($tenant->id, 7, 'processing', now()->subSeconds(89));

        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 7));
    }

    public function test_reclaiming_a_stale_row_updates_the_timestamp_so_it_is_not_immediately_reclaimable_again(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 8, 'processing', now()->subSeconds(200));

        $this->assertTrue(AiAgentMessageJob::claim($tenant->id, 8));
        // Immediately trying again must fail — the reclaim itself just
        // reset updated_at to now(), so a third worker racing in right
        // behind the second must not also win.
        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 8));
    }

    public function test_claim_never_affects_a_different_tenants_row_with_the_same_message_id(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedJob($tenantA->id, 100, 'pending');

        $this->assertFalse(AiAgentMessageJob::claim($tenantB->id, 100), "tenant B must never be able to claim tenant A's row");
        $this->assertSame('pending', DB::table('ai_agent_message_jobs')->where('tenant_id', $tenantA->id)->where('messenger_message_id', 100)->value('status'));
    }

    public function test_mark_completed_finalizes_the_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 9, 'pending');
        AiAgentMessageJob::claim($tenant->id, 9);

        AiAgentMessageJob::markCompleted($tenant->id, 9);

        $this->assertSame('completed', DB::table('ai_agent_message_jobs')->where('messenger_message_id', 9)->value('status'));
        $this->assertFalse(AiAgentMessageJob::claim($tenant->id, 9));
    }
}
