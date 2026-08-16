<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiHandoffService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers App\Services\AI\AiHandoffService — the Phase 13 real human
 * handoff mechanism. The single most important property under test is
 * that customerRequestedHuman() is narrow and precise enough to never
 * false-positive on an ordinary product question (see the service's own
 * class docblock for the "Human hair extension" example that motivates
 * this).
 */
class AiHandoffServiceTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
    }

    protected function service(): AiHandoffService
    {
        return app(AiHandoffService::class);
    }

    // --- customerRequestedHuman() ----------------------------------------------------------

    public function test_detects_an_explicit_english_request_for_a_human(): void
    {
        $this->assertTrue($this->service()->customerRequestedHuman('I want to talk to a human please'));
    }

    public function test_detects_an_explicit_bangla_request_for_a_human(): void
    {
        $this->assertTrue($this->service()->customerRequestedHuman('আমি একজন মানুষের সাথে কথা বলতে চাই'));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertTrue($this->service()->customerRequestedHuman('Can I TALK TO A HUMAN?'));
    }

    public function test_never_false_positives_on_a_real_product_question_mentioning_human(): void
    {
        // The exact false-positive risk this service's docblock warns
        // about — a bare "human" keyword would wrongly trigger here.
        $this->assertFalse($this->service()->customerRequestedHuman('Human hair extension আছে?'));
    }

    public function test_never_false_positives_on_an_ordinary_message(): void
    {
        $this->assertFalse($this->service()->customerRequestedHuman('COSRX Snail Cream টার দাম কত?'));
    }

    public function test_returns_false_for_null_or_empty_text(): void
    {
        $this->assertFalse($this->service()->customerRequestedHuman(null));
        $this->assertFalse($this->service()->customerRequestedHuman(''));
        $this->assertFalse($this->service()->customerRequestedHuman('   '));
    }

    // --- isActive() / trigger() / resolve() --------------------------------------------------

    public function test_is_not_active_when_no_handoff_has_ever_been_triggered(): void
    {
        $tenant = $this->makeTenant();

        $this->assertFalse($this->service()->isActive($tenant->id, 'messenger', 'psid-1'));
    }

    public function test_trigger_makes_isactive_true(): void
    {
        $tenant = $this->makeTenant();

        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->assertTrue($this->service()->isActive($tenant->id, 'messenger', 'psid-1'));
    }

    public function test_trigger_never_creates_a_duplicate_row_for_an_already_active_handoff(): void
    {
        $tenant = $this->makeTenant();

        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);
        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->assertSame(1, DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->count());
    }

    public function test_resolve_makes_isactive_false_again(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->service()->resolve($tenant->id, 'messenger', 'psid-1', $user->id);

        $this->assertFalse($this->service()->isActive($tenant->id, 'messenger', 'psid-1'));
    }

    public function test_resolve_records_who_resolved_it(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->service()->resolve($tenant->id, 'messenger', 'psid-1', $user->id);

        $row = DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->first();
        $this->assertSame($user->id, $row->resolved_by_user_id);
        $this->assertNotNull($row->resolved_at);
    }

    public function test_triggering_again_after_resolution_creates_a_fresh_row(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);
        $this->service()->resolve($tenant->id, 'messenger', 'psid-1', $user->id);

        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->assertTrue($this->service()->isActive($tenant->id, 'messenger', 'psid-1'));
        $this->assertSame(2, DB::table('ai_handoffs')->where('tenant_id', $tenant->id)->count());
    }

    public function test_messenger_and_whatsapp_handoffs_for_the_same_tenant_are_independent(): void
    {
        $tenant = $this->makeTenant();

        $this->service()->trigger($tenant->id, 'messenger', 'shared-id', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->assertTrue($this->service()->isActive($tenant->id, 'messenger', 'shared-id'));
        $this->assertFalse($this->service()->isActive($tenant->id, 'whatsapp', 'shared-id'));
    }

    public function test_never_leaks_tenant_as_handoff_to_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->service()->trigger($tenantA->id, 'messenger', 'shared-psid', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->assertFalse($this->service()->isActive($tenantB->id, 'messenger', 'shared-psid'));
    }

    public function test_resolving_tenant_as_handoff_never_affects_tenant_b(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->service()->trigger($tenantA->id, 'messenger', 'psid-a', AiHandoffService::REASON_CUSTOMER_REQUESTED);
        $this->service()->trigger($tenantB->id, 'messenger', 'psid-b', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->service()->resolve($tenantA->id, 'messenger', 'psid-a', $userA->id);

        $this->assertTrue($this->service()->isActive($tenantB->id, 'messenger', 'psid-b'));
    }

    public function test_isactive_degrades_to_false_when_the_table_does_not_exist(): void
    {
        $tenant = $this->makeTenant();
        Schema::dropIfExists('ai_handoffs');

        $this->assertFalse($this->service()->isActive($tenant->id, 'messenger', 'psid-1'));
    }

    public function test_trigger_degrades_silently_when_the_table_does_not_exist(): void
    {
        $tenant = $this->makeTenant();
        Schema::dropIfExists('ai_handoffs');

        // Must not throw.
        $this->service()->trigger($tenant->id, 'messenger', 'psid-1', AiHandoffService::REASON_CUSTOMER_REQUESTED);

        $this->assertTrue(true);
    }
}
