<?php

namespace Tests\Feature\CentralAuth;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * The actual, primary login path in this production's TENANCY_MODE=path
 * configuration — CentralLoginController's own docblock: "Single login
 * form on the central domain... works for ANY tenant's staff". Mirrors
 * tests/Feature/TenantAuth/LoginPersistenceTest.php's exact three tests,
 * which already cover the tenant-SLUG-prefixed login route
 * (tenant/login.blade.php) — that one already defaulted "remember" to
 * checked in an earlier commit. This central page had NOT received the
 * same fix; central/login.blade.php now matches it.
 */
class CentralLoginPersistenceTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function centralUrl(string $path): string
    {
        return 'http://'.config('app.central_domain').'/'.ltrim($path, '/');
    }

    public function test_remember_checkbox_defaults_to_checked(): void
    {
        $response = $this->get($this->centralUrl('login'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="remember"[^>]*checked/',
            $response->getContent(),
            'the central login page\'s remember-me checkbox must default to checked, same as the tenant-slug login page'
        );
    }

    public function test_login_with_remember_checked_issues_a_persistent_remember_cookie(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id, ['password' => bcrypt('secret123')]);

        $response = $this->post($this->centralUrl('login'), [
            'email' => $user->email,
            'password' => 'secret123',
            'remember' => '1',
        ]);

        $response->assertRedirect();

        $cookieNames = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());
        $this->assertTrue(
            $cookieNames->contains(fn ($name) => str_starts_with($name, 'remember_tenant_')),
            'expected the persistent remember_tenant_* cookie when remember=1 is submitted via the central login form'
        );
    }

    public function test_login_without_remember_does_not_issue_a_persistent_cookie(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id, ['password' => bcrypt('secret123')]);

        $response = $this->post($this->centralUrl('login'), [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertRedirect();

        $cookieNames = collect($response->headers->getCookies())->map(fn ($c) => $c->getName());
        $this->assertFalse(
            $cookieNames->contains(fn ($name) => str_starts_with($name, 'remember_tenant_')),
            'unchecking remember must still be honored on the central login form too'
        );
    }

    public function test_wrong_tenant_lookup_does_not_leave_a_dangling_session(): void
    {
        // A user whose tenant record no longer exists — CentralLoginController
        // logs them back out rather than leaving a half-authenticated state.
        $orphanTenant = $this->makeTenant();
        $user = $this->makeUser($orphanTenant->id, ['password' => bcrypt('secret123')]);
        \Illuminate\Support\Facades\DB::table('tenants')->where('id', $orphanTenant->id)->delete();

        $response = $this->post($this->centralUrl('login'), [
            'email' => $user->email,
            'password' => 'secret123',
            'remember' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
