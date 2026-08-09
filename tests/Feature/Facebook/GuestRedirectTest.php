<?php

namespace Tests\Feature\Facebook;

/**
 * Regression test for the "Route [login] not defined" 500 on any
 * unauthenticated request to an auth:tenant/auth:super_admin/auth:affiliate
 * route — see bootstrap/app.php's redirectGuestsTo() closure. Not Facebook-
 * specific (the bug is systemic to every guard in the app), but discovered
 * via GET /shop/{slug}/panel/facebook/connect, so verified here with that
 * exact route plus the other two guards for regression safety.
 */
class GuestRedirectTest extends FacebookFeatureTestCase
{
    public function test_unauthenticated_facebook_connect_redirects_to_tenant_login_not_a_500(): void
    {
        $tenant = $this->makeTenant(['subdomain' => 'shop2']);

        $response = $this->get('/shop/shop2/panel/facebook/connect');

        $response->assertRedirect(route('tenant.login', ['tenant_slug' => 'shop2']));
    }

    public function test_unauthenticated_tenant_dashboard_redirects_to_tenant_login(): void
    {
        $this->makeTenant(['subdomain' => 'anothershop']);

        $response = $this->get('/shop/anothershop/panel');

        $response->assertRedirect(route('tenant.login', ['tenant_slug' => 'anothershop']));
    }

    public function test_unauthenticated_super_admin_route_redirects_to_super_login(): void
    {
        $response = $this->get('/super-admin/tenants');

        $response->assertRedirect(route('super.login'));
    }

    public function test_unauthenticated_affiliate_route_redirects_to_affiliate_login(): void
    {
        $response = $this->get('/affiliate/dashboard');

        $response->assertRedirect(route('affiliate.login'));
    }
}
