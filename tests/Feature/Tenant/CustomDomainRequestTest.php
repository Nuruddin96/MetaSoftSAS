<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Tenant-side custom domain request workflow (Tenant/SettingController::
 * requestDomain()/cancelDomainRequest()) — the tenant can request their own
 * domain and, until now, had no way to cancel or correct a pending request.
 * The separate, already-approved custom_domain/custom_domain_verified
 * columns (what ResolveCustomDomain middleware actually routes production
 * traffic on) must never be touched by cancelling a request.
 */
class CustomDomainRequestTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    protected function makeProTenant(array $attrs = []): Tenant
    {
        $plan = $this->makePlan(['allow_custom_domain' => true]);

        return $this->makeTenant(array_merge(['plan_id' => $plan->id], $attrs));
    }

    public function test_a_pro_tenant_can_request_a_custom_domain(): void
    {
        $tenant = $this->makeProTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'settings/domain'), ['custom_domain_requested' => 'myshop.com']);

        $response->assertRedirect();
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

        $response = $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'settings/domain'), ['custom_domain_requested' => 'myshop.com']);

        $response->assertRedirect();
        $this->assertNull($tenant->fresh()->custom_domain_requested);
    }

    // --- the actual bug: no way to cancel/edit a pending request ---------------------------------

    public function test_a_pending_domain_request_can_be_cancelled(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain_requested' => 'typo-domain.com',
            'custom_domain_request_status' => 'pending',
            'custom_domain_verification_token' => 'sometoken123',
        ]);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->delete($this->panelUrl($tenant, 'settings/domain'));

        $response->assertRedirect();
        $tenant->refresh();
        $this->assertNull($tenant->custom_domain_requested);
        $this->assertSame('none', $tenant->custom_domain_request_status);
        $this->assertNull($tenant->custom_domain_verification_token);
    }

    public function test_a_dns_verified_domain_request_can_also_be_cancelled(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain_requested' => 'myshop.com',
            'custom_domain_request_status' => 'dns_verified',
            'custom_domain_verification_token' => 'sometoken123',
            'custom_domain_dns_verified_at' => now(),
        ]);
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, 'settings/domain'))->assertRedirect();

        $tenant->refresh();
        $this->assertSame('none', $tenant->custom_domain_request_status);
        $this->assertNull($tenant->custom_domain_dns_verified_at);
    }

    /** After cancelling, the tenant can submit a corrected domain — this is "editing" a request via cancel + resubmit. */
    public function test_after_cancelling_the_tenant_can_submit_a_corrected_domain(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain_requested' => 'typo-domain.com',
            'custom_domain_request_status' => 'pending',
        ]);
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, 'settings/domain'))->assertRedirect();
        $this->actingAs($user, 'tenant')
            ->post($this->panelUrl($tenant, 'settings/domain'), ['custom_domain_requested' => 'correct-domain.com'])
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame('correct-domain.com', $tenant->custom_domain_requested);
        $this->assertSame('pending', $tenant->custom_domain_request_status);
    }

    public function test_cancelling_with_no_pending_request_is_a_no_op_with_an_error(): void
    {
        $tenant = $this->makeProTenant(['custom_domain_request_status' => 'none']);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, 'settings/domain'));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    /** The safety rule from the task: cancelling a request must never touch an already-approved, live domain mapping. */
    public function test_cancelling_a_request_never_touches_an_already_approved_active_domain(): void
    {
        $tenant = $this->makeProTenant([
            'custom_domain' => 'live-store.com',
            'custom_domain_verified' => 1,
            // A tenant can have a live, approved domain AND, separately, a new
            // pending request in flight (e.g. requesting a second domain) —
            // cancelling that new request must leave the live one untouched.
            'custom_domain_requested' => 'second-domain.com',
            'custom_domain_request_status' => 'pending',
        ]);
        $user = $this->makeUser($tenant->id);

        $this->actingAs($user, 'tenant')->delete($this->panelUrl($tenant, 'settings/domain'))->assertRedirect();

        $tenant->refresh();
        $this->assertSame('live-store.com', $tenant->custom_domain);
        $this->assertTrue((bool) $tenant->custom_domain_verified);
        $this->assertNull($tenant->custom_domain_requested);
        $this->assertSame('none', $tenant->custom_domain_request_status);
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

        // Tenant B's own panel URL resolves app('currentTenant') to tenant B —
        // this can only ever cancel tenant B's own request, never A's, since
        // the controller reads app('currentTenant'), not a request parameter.
        $this->actingAs($userB, 'tenant')->delete($this->panelUrl($tenantB, 'settings/domain'))->assertRedirect();

        $this->assertSame('pending', $tenantA->fresh()->custom_domain_request_status);
        $this->assertSame('none', $tenantB->fresh()->custom_domain_request_status);
    }
}
