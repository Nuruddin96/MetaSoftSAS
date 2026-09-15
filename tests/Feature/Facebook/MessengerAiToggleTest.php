<?php

namespace Tests\Feature\Facebook;

use App\Models\MessengerMessage;
use App\Services\AI\AiHandoffService;
use Laravel\Sanctum\Sanctum;

/**
 * Covers the new "AI [toggle]" per-customer control (Messenger inbox) —
 * Tenant\MessengerInboxController::pauseAi() / Api\Mobile\MessengerController::
 * pauseAi(), both of which just call the existing AiHandoffService::trigger()
 * with a new REASON_MANUALLY_DISABLED reason (see AiHandoffServiceTest.php
 * for the service-level coverage of that reason itself, and
 * ProcessAiAgentMessage's own isActive() gate, confirmed by direct
 * inspection to be reason-agnostic, for why no AI-job pipeline change was
 * needed). This file covers the HTTP surface: routes wired correctly,
 * correct channel/psid passed through, tenant isolation, and that the
 * default state (no row at all) is "AI ON".
 */
class MessengerAiToggleTest extends FacebookFeatureTestCase
{
    protected function panelUrl(\App\Models\Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    private function makeConversation(int $tenantId, string $psid): void
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        MessengerMessage::create([
            'tenant_id' => $tenantId, 'sender_psid' => $psid, 'customer_name' => 'Karim',
            'message_text' => 'হ্যালো', 'direction' => 'in', 'status' => 'new',
        ]);
        app()->forgetInstance('currentTenant');
    }

    // --- Default state (no row) is AI ON ------------------------------------------------

    public function test_a_fresh_conversation_with_no_handoff_row_defaults_to_ai_on(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $handoffActive = app(AiHandoffService::class)->isActive($tenant->id, 'messenger', 'psid-1');
        app()->forgetInstance('currentTenant');

        $this->assertFalse($handoffActive, 'no handoff row at all must mean AI is ON by default');
    }

    // --- Mobile ---------------------------------------------------------------------------

    public function test_mobile_pause_ai_disables_auto_reply_for_that_psid_only(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');
        $this->makeConversation($tenant->id, 'psid-2');

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/v1/messenger/psid-1/pause-ai')->assertOk()->assertJsonPath('ok', true);

        app()->instance('currentTenant', $tenant);
        $this->assertTrue(app(AiHandoffService::class)->isActive($tenant->id, 'messenger', 'psid-1'));
        $this->assertFalse(app(AiHandoffService::class)->isActive($tenant->id, 'messenger', 'psid-2'));
        app()->forgetInstance('currentTenant');
    }

    public function test_mobile_pause_then_resume_turns_ai_back_on(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/v1/messenger/psid-1/pause-ai')->assertOk();
        $this->postJson('/api/mobile/v1/messenger/psid-1/resume-ai')->assertOk();

        app()->instance('currentTenant', $tenant);
        $this->assertFalse(app(AiHandoffService::class)->isActive($tenant->id, 'messenger', 'psid-1'));
        app()->forgetInstance('currentTenant');
    }

    public function test_mobile_show_reflects_the_current_toggle_state(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/v1/messenger/psid-1')->assertOk()->assertJsonPath('handoff_active', false);

        $this->postJson('/api/mobile/v1/messenger/psid-1/pause-ai')->assertOk();

        $this->getJson('/api/mobile/v1/messenger/psid-1')->assertOk()->assertJsonPath('handoff_active', true);
    }

    public function test_mobile_pause_ai_never_reaches_another_tenants_conversation(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->makeConversation($tenantA->id, 'shared-psid');

        Sanctum::actingAs($userB);
        $this->postJson('/api/mobile/v1/messenger/shared-psid/pause-ai')->assertOk();

        app()->instance('currentTenant', $tenantA);
        $this->assertFalse(
            app(AiHandoffService::class)->isActive($tenantA->id, 'messenger', 'shared-psid'),
            "tenant B's pause-ai call must never disable tenant A's AI"
        );
        app()->forgetInstance('currentTenant');
    }

    // --- Web ------------------------------------------------------------------------------

    public function test_web_pause_ai_disables_auto_reply(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'messenger/psid-1/pause-ai'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        app()->instance('currentTenant', $tenant);
        $this->assertTrue(app(AiHandoffService::class)->isActive($tenant->id, 'messenger', 'psid-1'));
        app()->forgetInstance('currentTenant');
    }

    public function test_web_pause_then_resume_turns_ai_back_on(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'messenger/psid-1/pause-ai'));
        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'messenger/psid-1/resume-ai'));

        app()->instance('currentTenant', $tenant);
        $this->assertFalse(app(AiHandoffService::class)->isActive($tenant->id, 'messenger', 'psid-1'));
        app()->forgetInstance('currentTenant');
    }

    /** Toggling off twice through the real HTTP endpoint (not just the service) must still never duplicate the handoff row. */
    public function test_web_pause_ai_called_twice_does_not_duplicate_the_handoff(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeConversation($tenant->id, 'psid-1');

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'messenger/psid-1/pause-ai'));
        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'messenger/psid-1/pause-ai'));

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('ai_handoffs')
            ->where('tenant_id', $tenant->id)->where('external_id', 'psid-1')->count());
    }
}
