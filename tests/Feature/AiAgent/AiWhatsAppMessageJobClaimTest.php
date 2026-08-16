<?php

namespace Tests\Feature\AiAgent;

use App\Models\AiWhatsAppMessageJob;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

/**
 * WhatsApp counterpart of AiAgentMessageJobClaimTest — covers
 * AiWhatsAppMessageJob::claim() directly, including the Phase 15
 * stale-'processing' reclaim fix. See that test class's docblock for the
 * full reasoning; this mirrors it one-for-one.
 */
class AiWhatsAppMessageJobClaimTest extends TestCase
{
    use InteractsWithWhatsAppSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema();
    }

    protected function seedJob(int $tenantId, int $whatsAppMessageId, string $status, ?\DateTimeInterface $updatedAt = null): void
    {
        DB::table('ai_whatsapp_message_jobs')->insert([
            'tenant_id' => $tenantId,
            'whatsapp_message_id' => $whatsAppMessageId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }

    public function test_claims_a_pending_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 1, 'pending');

        $this->assertTrue(AiWhatsAppMessageJob::claim($tenant->id, 1));
        $this->assertSame('processing', DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', 1)->value('status'));
    }

    public function test_a_second_claim_on_the_same_row_fails(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 2, 'pending');

        $this->assertTrue(AiWhatsAppMessageJob::claim($tenant->id, 2));
        $this->assertFalse(AiWhatsAppMessageJob::claim($tenant->id, 2));
    }

    public function test_a_completed_row_can_never_be_reclaimed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 3, 'completed');

        $this->assertFalse(AiWhatsAppMessageJob::claim($tenant->id, 3));
    }

    public function test_a_freshly_processing_row_cannot_be_reclaimed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 5, 'processing', now());

        $this->assertFalse(AiWhatsAppMessageJob::claim($tenant->id, 5));
    }

    public function test_a_stale_processing_row_past_retry_after_can_be_reclaimed(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 6, 'processing', now()->subSeconds(200));

        $this->assertTrue(AiWhatsAppMessageJob::claim($tenant->id, 6));
    }

    public function test_a_processing_row_exactly_at_the_retry_after_boundary_is_not_yet_reclaimed(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 7, 'processing', now()->subSeconds(89));

        $this->assertFalse(AiWhatsAppMessageJob::claim($tenant->id, 7));
    }

    public function test_claim_never_affects_a_different_tenants_row_with_the_same_message_id(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->seedJob($tenantA->id, 100, 'pending');

        $this->assertFalse(AiWhatsAppMessageJob::claim($tenantB->id, 100));
        $this->assertSame('pending', DB::table('ai_whatsapp_message_jobs')->where('tenant_id', $tenantA->id)->where('whatsapp_message_id', 100)->value('status'));
    }

    public function test_mark_completed_finalizes_the_row(): void
    {
        $tenant = $this->makeTenant();
        $this->seedJob($tenant->id, 9, 'pending');
        AiWhatsAppMessageJob::claim($tenant->id, 9);

        AiWhatsAppMessageJob::markCompleted($tenant->id, 9);

        $this->assertSame('completed', DB::table('ai_whatsapp_message_jobs')->where('whatsapp_message_id', 9)->value('status'));
        $this->assertFalse(AiWhatsAppMessageJob::claim($tenant->id, 9));
    }
}
