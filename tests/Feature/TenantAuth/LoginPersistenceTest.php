<?php

namespace Tests\Feature\TenantAuth;

use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers the mobile/PWA "stay logged in" fix: the panel login form's
 * "remember" checkbox now defaults to checked, so a normal login goes
 * through Laravel's built-in remember-me cookie (SessionGuard's
 * $rememberDuration, ~400 days) instead of only the plain session cookie
 * (SESSION_LIFETIME, 120 minutes idle). No custom auth, no bypass — this
 * is the stock Laravel mechanism, just defaulted on.
 */
class LoginPersistenceTest extends TestCase
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

    public function test_remember_checkbox_defaults_to_checked(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->panelUrl($tenant, 'login'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="remember"[^>]*checked/',
            $response->getContent(),
            'the remember-me checkbox must default to checked so closing/reopening the app does not force a fresh login'
        );
    }

    public function test_login_with_remember_checked_issues_a_persistent_remember_cookie(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id, ['password' => bcrypt('secret123')]);

        $response = $this->post($this->panelUrl($tenant, 'login'), [
            'email' => $user->email,
            'password' => 'secret123',
            'remember' => '1',
        ]);

        $response->assertRedirect();

        // SessionGuard::getRecallerName() = 'remember_'.$guardName.'_'.sha1(...)
        // — the "tenant" guard (config/auth.php), not "web", so the cookie
        // is remember_tenant_<hash>, not Laravel's more commonly-seen
        // remember_web_<hash>.
        $cookieNames = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());
        $this->assertTrue(
            $cookieNames->contains(fn ($name) => str_starts_with($name, 'remember_tenant_')),
            'expected Laravel\'s persistent remember_tenant_* cookie to be queued when remember=1 is submitted'
        );
    }

    public function test_login_without_remember_does_not_issue_a_persistent_cookie(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id, ['password' => bcrypt('secret123')]);

        $response = $this->post($this->panelUrl($tenant, 'login'), [
            'email' => $user->email,
            'password' => 'secret123',
            // no 'remember' key — simulates a user who explicitly unchecked it
        ]);

        $response->assertRedirect();

        $cookieNames = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());
        $this->assertFalse(
            $cookieNames->contains(fn ($name) => str_starts_with($name, 'remember_tenant_')),
            'unchecking remember must still be honored — no forced persistent login'
        );
    }
}
