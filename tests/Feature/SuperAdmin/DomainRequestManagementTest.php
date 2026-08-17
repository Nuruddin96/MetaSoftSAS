<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the Super Admin side of the simplified custom-domain
 * approve -> activate / deactivate / reject / delete workflow
 * (SuperAdmin\TenantController's domain methods) and the cross-tenant
 * list page (SuperAdmin\DomainRequestController). Uses
 * InteractsWithCommerceSchema (not InteractsWithAiAgentSchema) because
 * that trait's tenants table already carries the full custom_domain_*
 * column set these tests mutate — see CustomDomainRequestTest, which
 * covers the tenant-facing half of the same workflow with the same
 * trait. super_admins is stubbed locally rather than pulling in a second
 * schema trait, to avoid the cross-trait table-definition collision risk
 * both existing schema traits' docblocks already warn about.
 */
class DomainRequestManagementTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! Schema::hasTable('super_admins')) {
            Schema::create('super_admins', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    protected function makeSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    protected function makePendingTenant(array $attrs = []): Tenant
    {
        return $this->makeTenant(array_merge([
            'custom_domain_requested' => 'myshop.com',
            'custom_domain_request_status' => 'pending',
        ], $attrs));
    }

    // --- auth guard -----------------------------------------------------------------------

    public function test_guest_cannot_approve_a_domain_request(): void
    {
        $tenant = $this->makePendingTenant();

        $this->post(route('super.tenants.domain.approve', $tenant))->assertRedirect();

        $this->assertSame('pending', $tenant->fresh()->custom_domain_request_status);
    }

    // --- approve -> activate --------------------------------------------------------------

    public function test_super_admin_can_approve_a_pending_request(): void
    {
        $tenant = $this->makePendingTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.approve', $tenant))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame('approved', $tenant->custom_domain_request_status);
        // Approving must NOT go live by itself — see activateDomain()'s docblock.
        $this->assertFalse((bool) $tenant->custom_domain_verified);
        $this->assertNull($tenant->custom_domain);
    }

    public function test_super_admin_can_activate_an_approved_request(): void
    {
        $tenant = $this->makePendingTenant(['custom_domain_request_status' => 'approved']);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.activate', $tenant))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame('myshop.com', $tenant->custom_domain);
        $this->assertTrue((bool) $tenant->custom_domain_verified);
        $this->assertSame('active', $tenant->customDomainDisplayStatus());
    }

    public function test_activating_without_first_approving_is_rejected(): void
    {
        $tenant = $this->makePendingTenant(); // still 'pending'
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.activate', $tenant))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertNull($tenant->custom_domain);
        $this->assertFalse((bool) $tenant->custom_domain_verified);
    }

    public function test_activating_a_domain_already_live_on_another_tenant_is_rejected_gracefully(): void
    {
        $this->makeTenant(['custom_domain' => 'myshop.com', 'custom_domain_verified' => 1]);
        $tenant = $this->makePendingTenant(['custom_domain_request_status' => 'approved']);
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.activate', $tenant));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertFalse((bool) $tenant->fresh()->custom_domain_verified, 'must not silently mark this tenant active when the domain is already taken');
    }

    // --- deactivate / reactivate / delete --------------------------------------------------

    public function test_super_admin_can_deactivate_an_active_domain(): void
    {
        $tenant = $this->makeTenant(['custom_domain' => 'myshop.com', 'custom_domain_verified' => 1, 'custom_domain_request_status' => 'approved']);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.deactivate', $tenant))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertFalse((bool) $tenant->custom_domain_verified);
        // Deactivating must NOT clear the mapping — re-activation needs no re-request.
        $this->assertSame('myshop.com', $tenant->custom_domain);
    }

    public function test_super_admin_can_reactivate_after_deactivating(): void
    {
        $tenant = $this->makeTenant([
            'custom_domain' => 'myshop.com', 'custom_domain_verified' => 0,
            'custom_domain_requested' => 'myshop.com', 'custom_domain_request_status' => 'approved',
        ]);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.activate', $tenant))
            ->assertRedirect();

        $this->assertTrue((bool) $tenant->fresh()->custom_domain_verified);
    }

    public function test_deactivating_an_already_inactive_domain_is_a_no_op_with_an_error(): void
    {
        $tenant = $this->makePendingTenant();
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.deactivate', $tenant));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_super_admin_can_delete_an_active_domain_mapping(): void
    {
        $tenant = $this->makeTenant(['custom_domain' => 'myshop.com', 'custom_domain_verified' => 1, 'custom_domain_request_status' => 'approved']);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->delete(route('super.tenants.domain.destroy', $tenant))
            ->assertRedirect();

        $tenant->refresh();
        $this->assertNull($tenant->custom_domain);
        $this->assertFalse((bool) $tenant->custom_domain_verified);
        $this->assertSame('none', $tenant->custom_domain_request_status);
        $this->assertSame('none', $tenant->customDomainDisplayStatus());
    }

    // --- reject -----------------------------------------------------------------------------

    public function test_super_admin_can_reject_a_pending_request(): void
    {
        $tenant = $this->makePendingTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.tenants.domain.reject', $tenant))
            ->assertRedirect();

        $this->assertSame('rejected', $tenant->fresh()->custom_domain_request_status);
    }

    public function test_an_active_domain_cannot_be_rejected(): void
    {
        $tenant = $this->makeTenant(['custom_domain' => 'myshop.com', 'custom_domain_verified' => 1, 'custom_domain_request_status' => 'approved']);
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.reject', $tenant));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertTrue((bool) $tenant->fresh()->custom_domain_verified, 'an already-live domain must never be silently rejected out from under a tenant');
    }

    // --- cross-tenant list page -------------------------------------------------------------

    public function test_domain_requests_list_shows_tenants_with_domain_activity(): void
    {
        $tenant = $this->makePendingTenant(['store_name' => 'Domain Requesting Shop']);
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.domain-requests'));

        $response->assertOk();
        $response->assertSee('Domain Requesting Shop');
        $response->assertSee('myshop.com');
    }

    public function test_domain_requests_list_excludes_tenants_with_no_domain_activity(): void
    {
        $this->makeTenant(['store_name' => 'No Domain Activity Shop']);
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.domain-requests'));

        $response->assertOk();
        $response->assertDontSee('No Domain Activity Shop');
    }
}
