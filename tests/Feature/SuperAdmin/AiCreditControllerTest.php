<?php

namespace Tests\Feature\SuperAdmin;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers SuperAdmin\AiCreditController — the allocation/adjustment HTTP
 * endpoints. AiCreditServiceTest already covers the underlying service
 * contract in isolation; this file covers that the routes are wired
 * correctly, guarded by the super_admin auth guard, and that the
 * mutation actually lands via the real request/response cycle.
 */
class AiCreditControllerTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guest_cannot_allocate_credit(): void
    {
        $tenant = $this->makeTenant();

        $this->post(route('super.ai-credit.allocate', $tenant), ['amount' => 100])
            ->assertRedirect(); // to super admin login, not through

        $this->assertSame(0, DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->count());
    }

    public function test_super_admin_can_allocate_credit(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.allocate', $tenant), ['amount' => '100', 'note' => 'Signup bonus'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(
            100.0,
            (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'),
            0.0001
        );

        $row = DB::table('ai_usage_ledger')->where('tenant_id', $tenant->id)->first();
        $this->assertSame('allocation', $row->type);
        $this->assertSame($admin->id, $row->created_by);
    }

    public function test_super_admin_can_debit_via_adjustment(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->allocateAiCredit($tenant->id, 50);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.adjustments.store', $tenant), [
                'direction' => 'debit',
                'amount' => '20',
                'note' => 'ভুল বরাদ্দ সংশোধন',
            ])
            ->assertRedirect();

        $this->assertEqualsWithDelta(
            30.0,
            (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'),
            0.0001
        );
    }

    public function test_adjustment_requires_a_note(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        $this->allocateAiCredit($tenant->id, 50);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.adjustments.store', $tenant), [
                'direction' => 'credit',
                'amount' => '10',
                // no 'note' — must fail validation
            ])
            ->assertSessionHasErrors('note');

        $this->assertEqualsWithDelta(50.0, (float) DB::table('ai_credit_accounts')->where('tenant_id', $tenant->id)->value('balance'), 0.0001);
    }
}
