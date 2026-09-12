<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\StoreSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(\App\Models\Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
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

    // --- ai_custom_instructions (the same value the web "AI-কে আপনার ব্যবসার বিষয়ে কী কী জানা দরকার?" textarea reads/writes) ---

    public function test_index_defaults_custom_instructions_to_an_empty_string(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/ai-agent')->assertOk()->assertJsonPath('ai_custom_instructions', '');
    }

    public function test_update_persists_custom_instructions(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/ai-agent', [
            'ai_agent_enabled' => true,
            'ai_custom_instructions' => 'ঢাকার ভিতরে delivery charge ৮০ টাকা।',
        ])->assertOk();

        $this->assertDatabaseHas('store_settings', [
            'tenant_id' => $tenant->id, 'key' => 'ai_custom_instructions', 'value' => 'ঢাকার ভিতরে delivery charge ৮০ টাকা।',
        ]);
        $this->getJson('/api/mobile/v1/settings/ai-agent')
            ->assertOk()->assertJsonPath('ai_custom_instructions', 'ঢাকার ভিতরে delivery charge ৮০ টাকা।');
    }

    public function test_exactly_5000_characters_is_accepted_and_round_trips_in_full(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $text = str_repeat('a', 5000);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/ai-agent', ['ai_custom_instructions' => $text])->assertOk();

        $this->assertSame($text, StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('key', 'ai_custom_instructions')->value('value'));
    }

    public function test_over_5000_characters_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/ai-agent', ['ai_custom_instructions' => str_repeat('a', 5001)])
            ->assertStatus(422)->assertJsonValidationErrors('ai_custom_instructions');
    }

    public function test_tenant_a_cannot_see_or_overwrite_tenant_bs_custom_instructions(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        app()->instance('currentTenant', $tenantB);
        StoreSetting::updateOrCreate(['tenant_id' => $tenantB->id, 'key' => 'ai_custom_instructions'], ['value' => 'তেন্যান্ট B এর নিয়ম']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userA);

        $this->getJson('/api/mobile/v1/settings/ai-agent')->assertOk()->assertJsonPath('ai_custom_instructions', '');

        $this->postJson('/api/mobile/v1/settings/ai-agent', ['ai_custom_instructions' => 'তেন্যান্ট A এর নিয়ম'])->assertOk();

        $this->assertSame('তেন্যান্ট A এর নিয়ম', StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantA->id)->where('key', 'ai_custom_instructions')->value('value'));
        $this->assertSame('তেন্যান্ট B এর নিয়ম', StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantB->id)->where('key', 'ai_custom_instructions')->value('value'),
            "tenant B's instructions must be completely untouched by tenant A's request");
    }

    // --- Web/Mobile parity — same tenant-level store_settings row, either client can read what the other saved ---

    public function test_instructions_saved_on_web_are_readable_from_mobile(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'settings/ai-agent'), [
            'ai_custom_instructions' => 'ওয়েব থেকে সেভ করা নিয়ম',
        ])->assertRedirect();
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/v1/settings/ai-agent')
            ->assertOk()->assertJsonPath('ai_custom_instructions', 'ওয়েব থেকে সেভ করা নিয়ম');
    }

    public function test_instructions_saved_on_mobile_are_readable_from_web(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        Sanctum::actingAs($user);
        $this->postJson('/api/mobile/v1/settings/ai-agent', ['ai_custom_instructions' => 'মোবাইল থেকে সেভ করা নিয়ম'])->assertOk();

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'settings'));
        $response->assertOk()->assertSee('মোবাইল থেকে সেভ করা নিয়ম');
    }
}
