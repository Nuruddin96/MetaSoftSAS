<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiCreditService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiCreditService directly — the single source of
 * truth for AI credit balance mutations. MessengerAiDispatchTest and
 * ProcessAiAgentMessageJobTest cover this service through the real
 * dispatch/job flow; this file covers the service's own contract in
 * isolation (tenant isolation, first-allocation account creation, the
 * hasCredit() gate's exact boundary, usage/cost computation).
 */
class AiCreditServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function service(): AiCreditService
    {
        return app(AiCreditService::class);
    }

    public function test_a_tenant_never_allocated_credit_has_no_credit(): void
    {
        $tenant = $this->makeTenant();

        $this->assertNull($this->service()->balance($tenant->id));
        $this->assertFalse($this->service()->hasCredit($tenant->id));
    }

    public function test_first_allocation_creates_the_account_row(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->service()->allocate($tenant->id, 50.0, 'প্রথম বরাদ্দ', $admin->id);

        $this->assertEqualsWithDelta(50.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'), 0.0001);
        $this->assertTrue($this->service()->hasCredit($tenant->id));

        $row = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->first();
        $this->assertSame('allocation', $row->type);
        $this->assertSame($admin->id, $row->created_by);
    }

    public function test_balance_exactly_zero_has_no_credit(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 0);

        $this->assertFalse($this->service()->hasCredit($tenant->id), 'a balance of exactly 0 must be treated as exhausted, not "just enough"');
    }

    public function test_positive_balance_has_credit(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 0.01);

        $this->assertTrue($this->service()->hasCredit($tenant->id));
    }

    public function test_allocating_to_tenant_a_never_touches_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->allocateAiCredit($tenantB->id, 25);

        $this->service()->allocate($tenantA->id, 100.0, null, $admin->id);

        $this->assertEqualsWithDelta(100.0, (float) $this->service()->balance($tenantA->id), 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $this->service()->balance($tenantB->id), 0.0001, "tenant B's balance must be completely untouched by tenant A's allocation");
    }

    public function test_adjustment_credit_increases_balance(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->allocateAiCredit($tenant->id, 10);

        $this->service()->adjust($tenant->id, 5.0, 'credit', 'সংশোধন', $admin->id);

        $this->assertEqualsWithDelta(15.0, (float) $this->service()->balance($tenant->id), 0.0001);
    }

    public function test_adjustment_debit_decreases_balance_and_can_go_negative(): void
    {
        // Deliberately allowed to go negative — same "prepaid metered
        // usage" shape recordUsage() relies on: a call whose actual cost
        // exceeds the remaining balance still gets recorded (the tenant
        // already legitimately used it), it just means hasCredit() blocks
        // the NEXT call.
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->allocateAiCredit($tenant->id, 2);

        $this->service()->adjust($tenant->id, 5.0, 'debit', 'সংশোধন', $admin->id);

        $this->assertEqualsWithDelta(-3.0, (float) $this->service()->balance($tenant->id), 0.0001);
        $this->assertFalse($this->service()->hasCredit($tenant->id));
    }

    public function test_record_usage_deducts_credit_proportional_to_total_tokens(): void
    {
        config(['ai.credit_per_1k_tokens' => 2.0]);

        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        // 1500 total tokens @ 2.0 credit/1k = 3.0 credit.
        $this->service()->recordUsage($tenant->id, 1000, 500, 'gpt-5-mini', 'messenger_reply', 123);

        $this->assertEqualsWithDelta(97.0, (float) $this->service()->balance($tenant->id), 0.0001);

        $row = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->first();
        $this->assertSame(1000, $row->input_tokens);
        $this->assertSame(500, $row->output_tokens);
        $this->assertSame('gpt-5-mini', $row->model);
        $this->assertSame('messenger_reply', $row->context_type);
        $this->assertSame(123, $row->context_id);
        $this->assertNull($row->created_by, 'usage rows are system-generated, never attributed to a super admin');
    }

    public function test_record_usage_computes_an_admin_only_cost_estimate_separate_from_credit(): void
    {
        config([
            'ai.credit_per_1k_tokens' => 1.0,
            'ai.pricing' => ['gpt-5-mini' => ['input' => 1.0, 'output' => 2.0], 'default' => ['input' => 0, 'output' => 0]],
        ]);

        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        // 1000 input @ $1.0/1k + 1000 output @ $2.0/1k = $1.00 + $2.00 = $3.00.
        $this->service()->recordUsage($tenant->id, 1000, 1000, 'gpt-5-mini', 'messenger_reply');

        $row = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->first();
        $this->assertEqualsWithDelta(3.0, (float) $row->estimated_cost_usd, 0.000001);

        // Credit deducted uses the SEPARATE credit_per_1k_tokens rate
        // (2000 total tokens @ 1.0/1k = 2.0 credit), not the USD estimate.
        $this->assertEqualsWithDelta(98.0, (float) $this->service()->balance($tenant->id), 0.0001);
    }

    public function test_record_transcription_usage_deducts_credit_proportional_to_duration(): void
    {
        config(['ai.credit_per_minute_transcription' => 0.5]);

        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        // 30 seconds = 0.5 minutes @ 0.5 credit/minute = 0.25 credit.
        $this->service()->recordTranscriptionUsage($tenant->id, 30.0, 'whisper-1', 'messenger_voice_transcription', 456);

        $this->assertEqualsWithDelta(99.75, (float) $this->service()->balance($tenant->id), 0.0001);

        $row = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->first();
        $this->assertNull($row->input_tokens);
        $this->assertNull($row->output_tokens);
        $this->assertSame('whisper-1', $row->model);
        $this->assertSame('messenger_voice_transcription', $row->context_type);
        $this->assertSame(456, $row->context_id);
    }

    public function test_record_transcription_usage_computes_an_admin_only_cost_estimate_separate_from_credit(): void
    {
        config([
            'ai.credit_per_minute_transcription' => 1.0,
            'ai.pricing' => ['transcription' => ['whisper-1' => 0.006, 'default' => 0.006]],
        ]);

        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);

        // 2 minutes @ $0.006/minute = $0.012.
        $this->service()->recordTranscriptionUsage($tenant->id, 120.0, 'whisper-1', 'whatsapp_voice_transcription');

        $row = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->where('type', 'usage')->first();
        $this->assertEqualsWithDelta(0.012, (float) $row->estimated_cost_usd, 0.000001);

        // Credit deducted uses the separate credit_per_minute_transcription
        // rate (2 minutes @ 1.0/minute = 2.0 credit), not the USD estimate.
        $this->assertEqualsWithDelta(98.0, (float) $this->service()->balance($tenant->id), 0.0001);
    }

    public function test_record_transcription_usage_never_creates_a_wallet_that_does_not_already_exist(): void
    {
        $tenant = $this->makeTenant();

        $result = $this->service()->recordTranscriptionUsage($tenant->id, 30.0, 'whisper-1', 'messenger_voice_transcription');

        $this->assertNull($result);
        $this->assertSame(0, DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_record_transcription_usage_never_touches_a_different_tenants_balance(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->allocateAiCredit($tenantA->id, 50);
        $this->allocateAiCredit($tenantB->id, 50);

        $this->service()->recordTranscriptionUsage($tenantA->id, 60.0, 'whisper-1', 'messenger_voice_transcription');

        $this->assertLessThan(50, (float) $this->service()->balance($tenantA->id));
        $this->assertEqualsWithDelta(50.0, (float) $this->service()->balance($tenantB->id), 0.0001);
    }

    public function test_record_usage_never_creates_a_wallet_that_does_not_already_exist(): void
    {
        // Defense in depth: recordUsage() must never be the thing that
        // implicitly grants a tenant a wallet — only allocate()/adjust()
        // (explicit Super Admin actions) may create one. In practice this
        // path is never reached in the real flow (hasCredit() is always
        // checked first), but the method's own contract must hold anyway.
        $tenant = $this->makeTenant();

        $result = $this->service()->recordUsage($tenant->id, 10, 10, 'gpt-5-mini', 'messenger_reply');

        $this->assertNull($result);
        $this->assertSame(0, DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_ledger_tenant_visible_columns_hide_cost_and_token_data(): void
    {
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 100);
        $this->service()->recordUsage($tenant->id, 500, 500, 'gpt-5-mini', 'messenger_reply');

        $tenantVisible = $this->service()->ledger($tenant->id, tenantVisibleOnly: true)->first();

        $this->assertArrayNotHasKey('input_tokens', $tenantVisible->getAttributes());
        $this->assertArrayNotHasKey('output_tokens', $tenantVisible->getAttributes());
        $this->assertArrayNotHasKey('estimated_cost_usd', $tenantVisible->getAttributes());
        $this->assertArrayNotHasKey('created_by', $tenantVisible->getAttributes());
        $this->assertArrayHasKey('credit_amount', $tenantVisible->getAttributes());
    }
}
