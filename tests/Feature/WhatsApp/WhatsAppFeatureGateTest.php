<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Tenant;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\WhatsApp\WhatsAppOAuthService;

/**
 * 'feature:whatsapp' (EnsureFeatureEnabled, wired in routes/web.php) is the
 * enforcement half of the Super Admin plan-feature checkbox — config/
 * features.php + Plan::hasFeature() already existed (chunk27.sql) but
 * nothing gated tenant-facing routes on it until now. These tests cover the
 * gate itself; WhatsAppConnectionTest/WhatsAppInboxTest cover the
 * connect/inbox behavior on the allowed path (every makeTenant() there
 * already gets a whatsapp-enabled plan by default, see
 * InteractsWithWhatsAppSchema::makeTenant()).
 */
class WhatsAppFeatureGateTest extends WhatsAppFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_connect_is_blocked_when_the_tenants_plan_lacks_the_whatsapp_feature(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->makeWhatsAppEnabledPlan(['features' => ['facebook']])->id]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $state = $service->currentOrNewState($tenant, $user);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/connect'), [
            'state' => $state->state,
            'code' => 'good-code',
            'waba_id' => 'waba-1',
            'phone_number_id' => 'phone-1',
        ]);

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
        $this->assertNull(WhatsAppBusinessAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first());
    }

    public function test_connect_is_blocked_for_a_tenant_with_no_plan_assigned_at_all(): void
    {
        $tenant = $this->makeTenant(['plan_id' => null]);
        $user = $this->makeUser($tenant->id);
        $service = new WhatsAppOAuthService;
        $state = $service->currentOrNewState($tenant, $user);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'whatsapp/connect'), [
            'state' => $state->state,
            'code' => 'good-code',
            'waba_id' => 'waba-1',
            'phone_number_id' => 'phone-1',
        ]);

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }

    public function test_inbox_thread_is_blocked_when_plan_lacks_the_feature_even_with_existing_messages(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->makeWhatsAppEnabledPlan(['features' => []])->id]);
        $user = $this->makeUser($tenant->id);

        WhatsAppMessage::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'wa_id' => '8801700000000', 'wamid' => 'wamid-1',
            'message_type' => 'text', 'message_text' => 'hi', 'direction' => 'in', 'status' => 'new',
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'whatsapp/8801700000000'));

        $response->assertRedirect(route('tenant.settings', ['tenant_slug' => $tenant->subdomain]));
        $response->assertSessionHas('error');
    }

    public function test_updates_polling_endpoint_returns_403_json_when_plan_lacks_the_feature(): void
    {
        $tenant = $this->makeTenant(['plan_id' => $this->makeWhatsAppEnabledPlan(['features' => []])->id]);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->getJson($this->panelUrl($tenant, 'whatsapp/updates?after_id=0'));

        $response->assertStatus(403);
    }

    public function test_disconnect_remains_reachable_even_when_the_plan_no_longer_includes_the_feature(): void
    {
        $plan = $this->makeWhatsAppEnabledPlan();
        $tenant = $this->makeTenant(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);

        $account = WhatsAppBusinessAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'connected_by_user_id' => $user->id,
            'waba_id' => 'waba-downgrade', 'user_access_token' => 'tok',
        ]);
        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'whatsapp_business_account_id' => $account->id,
            'phone_number_id' => 'phone-downgrade', 'is_active' => true, 'status' => 'active',
        ]);

        // Downgrade the plan out from under the tenant after connecting.
        $plan->update(['features' => []]);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'whatsapp/'.$phone->id.'/disconnect'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertFalse((bool) $phone->fresh()->is_active);
    }
}
