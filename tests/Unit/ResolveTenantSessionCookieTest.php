<?php

namespace Tests\Unit;

use App\Http\Middleware\ResolveTenantSessionCookie;
use Illuminate\Http\Request;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Unit-level contract test for the subdomain-tenancy-mode session-cookie
 * fix — see ResolveTenantSessionCookie's docblock for the bug this closes.
 * Deliberately invokes the middleware directly rather than through a real
 * HTTP round-trip: routes/web.php decides path-vs-subdomain ROUTE
 * STRUCTURE once, at boot, from the TENANCY_MODE env var phpunit.xml pins
 * to "path" for the whole suite — a runtime config() override in a test
 * can't retroactively change which routes exist. What actually matters for
 * this fix is a narrower, testable contract: this middleware must set
 * config('session.cookie') to the resolved tenant's sess_{id} BEFORE
 * calling $next() (i.e. before StartSession, which lives inside $next() in
 * the real app, ever gets a chance to read it) whenever the app is in
 * subdomain mode and the host resolves to a tenant — and must leave it
 * alone in every other case.
 */
class ResolveTenantSessionCookieTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();

        config([
            'app.tenancy_mode' => 'subdomain',
            'app.central_domain' => 'example.test',
        ]);
    }

    public function test_it_names_the_session_cookie_after_the_resolved_tenant_before_calling_next(): void
    {
        $tenant = $this->makeTenant(['subdomain' => 'acme']);
        $request = Request::create('http://acme.example.test/panel/', 'GET');

        $seenAtNextTime = null;

        (new ResolveTenantSessionCookie)->handle($request, function ($req) use (&$seenAtNextTime) {
            $seenAtNextTime = config('session.cookie');

            return response('ok');
        });

        $this->assertSame(
            'sess_'.$tenant->id,
            $seenAtNextTime,
            'session.cookie must already be renamed by the time $next() runs, not after'
        );
    }

    public function test_it_leaves_the_session_cookie_alone_on_the_central_domain(): void
    {
        $original = config('session.cookie');
        $request = Request::create('http://example.test/', 'GET');

        (new ResolveTenantSessionCookie)->handle($request, fn ($req) => response('ok'));

        $this->assertSame($original, config('session.cookie'));
    }

    public function test_it_leaves_the_session_cookie_alone_for_an_unresolvable_host(): void
    {
        $original = config('session.cookie');
        $request = Request::create('http://no-such-tenant.example.test/', 'GET');

        (new ResolveTenantSessionCookie)->handle($request, fn ($req) => response('ok'));

        $this->assertSame($original, config('session.cookie'));
    }

    public function test_it_is_a_no_op_in_path_tenancy_mode(): void
    {
        config(['app.tenancy_mode' => 'path']);
        $this->makeTenant(['subdomain' => 'acme']);
        $original = config('session.cookie');

        $request = Request::create('http://acme.example.test/', 'GET');

        (new ResolveTenantSessionCookie)->handle($request, fn ($req) => response('ok'));

        $this->assertSame($original, config('session.cookie'));
    }
}
