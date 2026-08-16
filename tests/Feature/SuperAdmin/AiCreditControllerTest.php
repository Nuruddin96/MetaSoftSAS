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

    // --- Phase 14: index/show rendering + platform pause -----------------------------------------

    public function test_index_page_renders_without_error(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->makeTenant();

        $this->actingAs($admin, 'super_admin')->get(route('super.ai-credit.index'))->assertOk();
    }

    public function test_show_page_renders_without_error(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $this->allocateAiCredit($tenant->id, 50);

        $this->actingAs($admin, 'super_admin')->get(route('super.ai-credit.show', $tenant))->assertOk();
    }

    public function test_show_page_reflects_the_tenants_own_toggles(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $this->enableAiAgentAndMessengerAutoReply($tenant->id);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.ai-credit.show', $tenant));

        $response->assertOk();
        $response->assertViewHas('toggles', function (array $toggles) {
            return $toggles['ai_agent_enabled'] === true
                && $toggles['messenger_ai_auto_reply_enabled'] === true
                && $toggles['whatsapp_ai_auto_reply_enabled'] === false;
        });
    }

    public function test_guest_cannot_pause_ai(): void
    {
        $tenant = $this->makeTenant();

        $this->post(route('super.ai-credit.pause-ai', $tenant), ['reason' => 'abuse'])->assertRedirect();

        $this->assertNull(DB::table('tenants')->where('id', $tenant->id)->value('ai_paused_at'));
    }

    public function test_super_admin_can_pause_ai(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.pause-ai', $tenant), ['reason' => 'সন্দেহজনক ব্যবহার'])
            ->assertRedirect();

        $row = DB::table('tenants')->where('id', $tenant->id)->first();
        $this->assertNotNull($row->ai_paused_at);
        $this->assertSame($admin->id, $row->ai_paused_by_super_admin_id);
        $this->assertSame('সন্দেহজনক ব্যবহার', $row->ai_paused_reason);
    }

    public function test_pause_ai_requires_a_reason(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.pause-ai', $tenant), [])
            ->assertSessionHasErrors('reason');

        $this->assertNull(DB::table('tenants')->where('id', $tenant->id)->value('ai_paused_at'));
    }

    public function test_super_admin_can_resume_ai(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeSuperAdmin();
        DB::table('tenants')->where('id', $tenant->id)->update([
            'ai_paused_at' => now(), 'ai_paused_by_super_admin_id' => $admin->id, 'ai_paused_reason' => 'test',
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.resume-ai', $tenant))
            ->assertRedirect();

        $row = DB::table('tenants')->where('id', $tenant->id)->first();
        $this->assertNull($row->ai_paused_at);
        $this->assertNull($row->ai_paused_by_super_admin_id);
        $this->assertNull($row->ai_paused_reason);
    }

    public function test_pausing_tenant_a_never_affects_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.ai-credit.pause-ai', $tenantA), ['reason' => 'test']);

        $this->assertNull(DB::table('tenants')->where('id', $tenantB->id)->value('ai_paused_at'));
    }

    public function test_index_filters_by_paused_status(): void
    {
        $admin = $this->makeSuperAdmin();
        $pausedTenant = $this->makeTenant();
        $this->makeTenant();
        DB::table('tenants')->where('id', $pausedTenant->id)->update(['ai_paused_at' => now()]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.ai-credit.index', ['status' => 'paused']));

        $response->assertOk();
        $response->assertSee($pausedTenant->store_name);
    }
}
