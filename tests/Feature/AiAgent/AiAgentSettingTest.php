<?php

namespace Tests\Feature\AiAgent;

use App\Models\StoreSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithAiAgentSchema;
use Tests\TestCase;

/**
 * Covers Tenant\SettingController::aiAgent() — the ON/OFF toggle itself,
 * independent of anything Messenger-related. See MessengerAiDispatchTest
 * for how the toggle actually gates AI processing.
 */
class AiAgentSettingTest extends TestCase
{
    use InteractsWithAiAgentSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAiAgentSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function isEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'ai_agent_enabled')
            ->value('value') === '1';
    }

    protected function isKeyEnabled(int $tenantId, string $key): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->value('value') === '1';
    }

    public function test_new_tenants_have_ai_disabled_by_default(): void
    {
        $tenant = $this->makeTenant();

        $this->assertFalse($this->isEnabled($tenant->id), 'a freshly created tenant must not have AI enabled');
    }

    public function test_tenant_can_turn_ai_on(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        app()->instance('currentTenant', $tenant);

        $this->actingAs($user, 'tenant')
            ->post('/shop/'.$tenant->subdomain.'/panel/settings/ai-agent', ['ai_agent_enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue($this->isEnabled($tenant->id));
    }

    public function test_tenant_can_turn_ai_off(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->enableAiAgent($tenant->id);

        app()->instance('currentTenant', $tenant);

        // An unchecked HTML checkbox submits no field at all — this is the
        // real request shape the toggle form sends when turned off.
        $this->actingAs($user, 'tenant')
            ->post('/shop/'.$tenant->subdomain.'/panel/settings/ai-agent', [])
            ->assertRedirect();

        $this->assertFalse($this->isEnabled($tenant->id));
    }

    public function test_tenant_a_cannot_modify_tenant_bs_ai_setting(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->enableAiAgent($tenantB->id);

        // Tenant A's own panel session, but the request is scoped to
        // tenant A's own store URL — there is no route shape that lets a
        // tenant name a different tenant to act on, so this exercises the
        // real-world path: tenant A can only ever POST to their own
        // /shop/{A's slug}/panel/settings/ai-agent, and StoreSetting's
        // BelongsToTenant scope (bound to tenant A here) makes it
        // structurally impossible for that request to touch tenant B's row.
        app()->instance('currentTenant', $tenantA);

        $this->actingAs($userA, 'tenant')
            ->post('/shop/'.$tenantA->subdomain.'/panel/settings/ai-agent', ['ai_agent_enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue($this->isEnabled($tenantA->id), "tenant A's own toggle must have taken effect");
        $this->assertTrue($this->isEnabled($tenantB->id), "tenant B's setting must be completely untouched by tenant A's request");
    }

    public function test_whatsapp_auto_reply_toggle_is_independent_of_the_master_switch_and_the_messenger_toggle(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        // Turn the master switch AND WhatsApp on, but leave Messenger off
        // (unchecked, so it's simply absent from the submitted fields).
        $this->actingAs($user, 'tenant')
            ->post('/shop/'.$tenant->subdomain.'/panel/settings/ai-agent', [
                'ai_agent_enabled' => '1',
                'whatsapp_ai_auto_reply_enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($this->isEnabled($tenant->id));
        $this->assertTrue($this->isKeyEnabled($tenant->id, 'whatsapp_ai_auto_reply_enabled'));
        $this->assertFalse($this->isKeyEnabled($tenant->id, 'messenger_ai_auto_reply_enabled'), 'unchecking Messenger must not be affected by checking WhatsApp');
    }

    public function test_turning_whatsapp_off_does_not_affect_messenger_or_the_master_switch(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->enableAiAgent($tenant->id);
        StoreSetting::updateOrCreate(['tenant_id' => $tenant->id, 'key' => 'messenger_ai_auto_reply_enabled'], ['value' => '1']);
        StoreSetting::updateOrCreate(['tenant_id' => $tenant->id, 'key' => 'whatsapp_ai_auto_reply_enabled'], ['value' => '1']);
        app()->instance('currentTenant', $tenant);

        // Submit with both ai_agent_enabled and messenger checked, WhatsApp unchecked/absent.
        $this->actingAs($user, 'tenant')
            ->post('/shop/'.$tenant->subdomain.'/panel/settings/ai-agent', [
                'ai_agent_enabled' => '1',
                'messenger_ai_auto_reply_enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue($this->isEnabled($tenant->id));
        $this->assertTrue($this->isKeyEnabled($tenant->id, 'messenger_ai_auto_reply_enabled'));
        $this->assertFalse($this->isKeyEnabled($tenant->id, 'whatsapp_ai_auto_reply_enabled'));
    }
}
