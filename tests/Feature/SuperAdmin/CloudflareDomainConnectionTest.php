<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\SuperAdmin;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the "Connect" / "Refresh status" Cloudflare workflow
 * (SuperAdmin\TenantController::connectDomain()/refreshDomainConnection(),
 * App\Services\Domain\CloudflareDomainService). Never calls the real
 * Cloudflare API — every Cloudflare and self-verification HTTP call is
 * mocked via Http::fake(), per the task's explicit "do not call the real
 * Cloudflare API in automated tests" requirement.
 */
class CloudflareDomainConnectionTest extends TestCase
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

    protected function makeApprovedTenant(array $attrs = []): Tenant
    {
        return $this->makeTenant(array_merge([
            'custom_domain_requested' => 'mystore.com',
            'custom_domain_request_status' => 'approved',
        ], $attrs));
    }

    public function test_connect_without_cloudflare_configured_falls_back_to_dns_required(): void
    {
        config(['services.cloudflare.token' => null, 'services.cloudflare.zone_id' => null]);
        $tenant = $this->makeApprovedTenant();
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.connect', $tenant));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $tenant->refresh();
        $this->assertSame('dns_required', $tenant->custom_domain_connect_status);
        $this->assertNull($tenant->cf_custom_hostname_id);
        // Must never falsely mark it verified.
        $this->assertFalse((bool) $tenant->custom_domain_verified);
    }

    public function test_connect_with_cloudflare_configured_stores_the_custom_hostname_id(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'cf-hostname-123', 'status' => 'pending_validation', 'ssl' => ['status' => 'pending_validation']],
            ]),
        ]);

        $tenant = $this->makeApprovedTenant();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.connect', $tenant))->assertRedirect();

        $tenant->refresh();
        $this->assertSame('cf-hostname-123', $tenant->cf_custom_hostname_id);
        $this->assertSame('connecting', $tenant->custom_domain_connect_status);
        $this->assertFalse((bool) $tenant->custom_domain_verified);
    }

    public function test_connect_surfaces_a_cloudflare_api_error_as_failed(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Custom Hostnames is not enabled for this zone.']],
            ], 403),
        ]);

        $tenant = $this->makeApprovedTenant();
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.connect', $tenant));

        $response->assertSessionHas('error');
        $tenant->refresh();
        $this->assertSame('failed', $tenant->custom_domain_connect_status);
        $this->assertSame('Custom Hostnames is not enabled for this zone.', $tenant->custom_domain_connect_error);
    }

    public function test_refresh_when_cloudflare_active_but_origin_not_yet_verified_does_not_activate(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'cf-hostname-123', 'status' => 'active', 'ssl' => ['status' => 'active']],
            ]),
            // The self-verification ping fails (origin not routing this
            // domain yet — the confirmed Hostinger origin-vhost gap).
            'https://mystore.com/*' => Http::response([], 404),
        ]);

        $tenant = $this->makeApprovedTenant(['cf_custom_hostname_id' => 'cf-hostname-123', 'custom_domain_connect_status' => 'connecting']);
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.refresh', $tenant));

        $response->assertSessionHas('error');
        $tenant->refresh();
        $this->assertSame('connected', $tenant->custom_domain_connect_status);
        $this->assertFalse((bool) $tenant->custom_domain_verified, 'must never mark active on Cloudflare status alone');
        $this->assertNull($tenant->custom_domain);
    }

    public function test_refresh_activates_the_domain_once_both_cloudflare_and_the_origin_confirm(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);

        $tenant = $this->makeApprovedTenant(['cf_custom_hostname_id' => 'cf-hostname-123', 'custom_domain_connect_status' => 'connecting']);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'cf-hostname-123', 'status' => 'active', 'ssl' => ['status' => 'active']],
            ]),
            'https://mystore.com/*' => Http::response(['ok' => true, 'tenant_id' => $tenant->id]),
        ]);

        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.refresh', $tenant));

        $response->assertSessionHas('success');
        $tenant->refresh();
        $this->assertSame('mystore.com', $tenant->custom_domain);
        $this->assertTrue((bool) $tenant->custom_domain_verified);
        $this->assertSame('connected', $tenant->custom_domain_connect_status);
        $this->assertSame('active', $tenant->customDomainConnectionStatus());
    }

    public function test_refresh_still_pending_reports_connecting_without_activating(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'cf-hostname-123', 'status' => 'pending_validation', 'ssl' => ['status' => 'pending_validation']],
            ]),
        ]);

        $tenant = $this->makeApprovedTenant(['cf_custom_hostname_id' => 'cf-hostname-123', 'custom_domain_connect_status' => 'connecting']);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.refresh', $tenant))->assertRedirect();

        $tenant->refresh();
        $this->assertSame('connecting', $tenant->custom_domain_connect_status);
        $this->assertFalse((bool) $tenant->custom_domain_verified);
    }

    public function test_refresh_without_a_prior_connect_is_a_no_op_with_an_error(): void
    {
        $tenant = $this->makeApprovedTenant();
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.tenants.domain.refresh', $tenant));

        $response->assertSessionHas('error');
    }

    public function test_guest_cannot_connect_or_refresh(): void
    {
        $tenant = $this->makeApprovedTenant();

        $this->post(route('super.tenants.domain.connect', $tenant))->assertRedirect();
        $this->post(route('super.tenants.domain.refresh', $tenant))->assertRedirect();

        $this->assertSame('not_connected', $tenant->fresh()->custom_domain_connect_status);
    }

    public function test_destroy_domain_best_effort_deletes_the_cloudflare_hostname_and_resets_local_state(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

        $tenant = $this->makeTenant([
            'custom_domain' => 'mystore.com', 'custom_domain_verified' => 1,
            'custom_domain_requested' => 'mystore.com', 'custom_domain_request_status' => 'approved',
            'cf_custom_hostname_id' => 'cf-hostname-123', 'custom_domain_connect_status' => 'connected',
        ]);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->delete(route('super.tenants.domain.destroy', $tenant))->assertRedirect();

        $tenant->refresh();
        $this->assertNull($tenant->custom_domain);
        $this->assertNull($tenant->cf_custom_hostname_id);
        $this->assertSame('not_connected', $tenant->custom_domain_connect_status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'custom_hostnames/cf-hostname-123') && $request->method() === 'DELETE');
    }

    public function test_destroy_domain_still_succeeds_even_if_the_cloudflare_delete_call_fails(): void
    {
        config(['services.cloudflare.token' => 'test-token', 'services.cloudflare.zone_id' => 'test-zone']);
        Http::fake(['api.cloudflare.com/*' => Http::response([], 500)]);

        $tenant = $this->makeTenant([
            'custom_domain' => 'mystore.com', 'custom_domain_verified' => 1,
            'cf_custom_hostname_id' => 'cf-hostname-123', 'custom_domain_connect_status' => 'connected',
        ]);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->delete(route('super.tenants.domain.destroy', $tenant))->assertRedirect();

        $this->assertNull($tenant->fresh()->custom_domain);
    }
}
