<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\SettingController::domain()/requestDomain()/
 * cancelDomain() — mirrors Tenant\SettingController::requestDomain()/
 * cancelDomainRequest() exactly (Website Builder parity task). Test
 * scenarios mirror tests/Feature/Tenant/CustomDomainRequestTest.php
 * (the web equivalent) adapted for Sanctum/JSON.
 */
class DomainSettingApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    protected function makeProTenant(array $attrs = []): Tenant
    {
        $plan = $this->makePlan(['allow_custom_domain' => true]);

        return $this->makeTenant(array_merge(['plan_id' => $plan->id], $attrs));
    }

    public function test_domain_status_reflects_no_request_by_default(): void
    {
        $tenant = $this->makeProTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/domain')->assertOk()
            ->assertJsonPath('allow_custom_domain', true)
            ->assertJsonPath('status', 'none')
            ->assertJsonPath('custom_domain', null);
    }

    public function test_domain_status_is_false_without_the_plan_feature(): void
    {
        $plan = $this->makePlan(['allow_custom_domain' => false]);
        $tenant = $this->makeTenant(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/domain')->assertOk()
            ->assertJsonPath('allow_custom_domain', false);
    }

    public function test_a_pro_tenant_can_request_a_custom_domain(): void
    {
        $tenant = $this->makeProTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/domain', ['custom_domain_requested' => 'myshop.com'])
            ->assertOk()->assertJsonPath('ok', true);

        $tenant->refresh();
        $this->assertSame('myshop.com', $tenant->custom_domain_requested);
        $this->assertSame('pending', $tenant->custom_domain_request_status);
        $this->assertNotNull($tenant->custom_domain_verification_token);
    }

    public function test_a_tenant_without_the_custom_domain_plan_feature_is_rejected(): void
    {
        $plan = $this->makePlan(['allow_custom_domain' => false]);
        $tenant = $this->makeTenant(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/domain', ['custom_domain_requested' => 'myshop.com'])
            ->assertStatus(422);

        $this->assertNull($tenant->fresh()->custom_domain_requested);
    }

    public function test_an_invalid_domain_format_is_rejected(): void
    {
        $tenant = $this->makeProTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/settings/domain', ['custom_domain_requested' => 'not a domain'])
            ->assertStatus(422);
    }

    public function test_a_pending_domain_request_can_be_cancelled(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain_requested' => 'typo-domain.com',
            'custom_domain_request_status' => 'pending',
            'custom_domain_verification_token' => 'sometoken123',
        ]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/mobile/v1/settings/domain')->assertOk()->assertJsonPath('ok', true);

        $tenant->refresh();
        $this->assertNull($tenant->custom_domain_requested);
        $this->assertSame('none', $tenant->custom_domain_request_status);
        $this->assertNull($tenant->custom_domain_verification_token);
    }

    public function test_cancelling_with_no_pending_request_returns_an_error(): void
    {
        $tenant = $this->makeProTenant(['custom_domain_request_status' => 'none']);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/mobile/v1/settings/domain')->assertStatus(422);
    }

    public function test_cancelling_a_request_never_touches_an_already_approved_active_domain(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain' => 'live-store.com',
            'custom_domain_verified' => 1,
            'custom_domain_requested' => 'second-domain.com',
            'custom_domain_request_status' => 'pending',
        ]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/mobile/v1/settings/domain')->assertOk();

        $tenant->refresh();
        $this->assertSame('live-store.com', $tenant->custom_domain);
        $this->assertTrue((bool) $tenant->custom_domain_verified);
        $this->assertNull($tenant->custom_domain_requested);
        $this->assertSame('none', $tenant->custom_domain_request_status);
    }

    public function test_domain_status_shows_active_once_verified(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain' => 'live-store.com',
            'custom_domain_verified' => 1,
        ]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/settings/domain')->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('custom_domain', 'live-store.com');
    }

    public function test_a_tenant_cannot_cancel_another_tenants_domain_request(): void
    {
        $tenantA = $this->makeProTenant([
            'custom_domain_requested' => 'tenant-a-domain.com',
            'custom_domain_request_status' => 'pending',
        ]);
        $tenantB = $this->makeProTenant([
            'custom_domain_requested' => 'tenant-a-domain.com',
            'custom_domain_request_status' => 'pending',
        ]);
        $userB = $this->makeUser($tenantB->id);
        Sanctum::actingAs($userB);

        $this->deleteJson('/api/mobile/v1/settings/domain')->assertOk();

        $this->assertSame('pending', $tenantA->fresh()->custom_domain_request_status);
        $this->assertSame('none', $tenantB->fresh()->custom_domain_request_status);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/settings/domain')->assertUnauthorized();
        $this->postJson('/api/mobile/v1/settings/domain', [])->assertUnauthorized();
        $this->deleteJson('/api/mobile/v1/settings/domain')->assertUnauthorized();
    }
}
