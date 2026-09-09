<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\StoreSetting;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Mobile parity for the master AI switch + per-channel auto-reply toggles
 * (Tenant\SettingController::aiAgent()'s mobile counterpart) — the mobile
 * app's toggles were previously local-only (no persistence at all).
 */
class AiAgentSettingControllerTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    public function test_index_defaults_to_all_off_when_nothing_saved_yet(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/ai-agent')
            ->assertOk()
            ->assertJson([
                'ai_agent_enabled' => false,
                'messenger_ai_auto_reply_enabled' => false,
                'whatsapp_ai_auto_reply_enabled' => false,
            ]);
    }

    public function test_update_persists_each_toggle_independently(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/ai-agent', [
            'ai_agent_enabled' => true,
            'messenger_ai_auto_reply_enabled' => true,
            'whatsapp_ai_auto_reply_enabled' => false,
        ])->assertOk();

        $this->assertDatabaseHas('store_settings', ['tenant_id' => $tenant->id, 'key' => 'ai_agent_enabled', 'value' => '1']);
        $this->assertDatabaseHas('store_settings', ['tenant_id' => $tenant->id, 'key' => 'messenger_ai_auto_reply_enabled', 'value' => '1']);
        $this->assertDatabaseHas('store_settings', ['tenant_id' => $tenant->id, 'key' => 'whatsapp_ai_auto_reply_enabled', 'value' => '0']);

        $this->getJson('/api/mobile/v1/settings/ai-agent')
            ->assertOk()
            ->assertJson([
                'ai_agent_enabled' => true,
                'messenger_ai_auto_reply_enabled' => true,
                'whatsapp_ai_auto_reply_enabled' => false,
            ]);
    }

    /** Turning the master switch back off must actually flip the stored value off, not just stop sending it. */
    public function test_update_can_turn_an_already_on_toggle_back_off(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'ai_agent_enabled', 'value' => '1']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/ai-agent', [
            'ai_agent_enabled' => false,
            'messenger_ai_auto_reply_enabled' => false,
            'whatsapp_ai_auto_reply_enabled' => false,
        ])->assertOk();

        $this->assertDatabaseHas('store_settings', ['tenant_id' => $tenant->id, 'key' => 'ai_agent_enabled', 'value' => '0']);
    }

    public function test_guest_cannot_read_or_update(): void
    {
        $this->getJson('/api/mobile/v1/settings/ai-agent')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/ai-agent', ['ai_agent_enabled' => true])->assertUnauthorized();
    }
}
