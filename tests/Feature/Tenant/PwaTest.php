<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * The PWA manifest/service-worker routes are new (added for the mobile
 * app-shell work) — this covers what a live audit can't: that they
 * resolve at all, that the manifest's start_url/scope are correctly
 * tenant-scoped (not a hardcoded/wrong tenant), and — the security-
 * critical part — that the generated service worker script never
 * contains anything beyond static asset URLs.
 */
class PwaTest extends TestCase
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

    public function test_manifest_resolves_and_is_scoped_to_the_current_tenants_panel(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'manifest.json'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();
        $this->assertSame('MetaSoft SaaS', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertStringContainsString($tenant->subdomain, $manifest['start_url']);
        $this->assertStringContainsString('/panel', $manifest['start_url']);
        $this->assertStringContainsString($tenant->subdomain, $manifest['scope']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_manifest_for_a_different_tenant_never_points_at_another_tenants_panel(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        $response = $this->actingAs($userA, 'tenant')->get($this->panelUrl($tenantA, 'manifest.json'));

        $response->assertOk();
        $manifest = $response->json();
        $this->assertStringNotContainsString($tenantB->subdomain, $manifest['start_url']);
        $this->assertStringNotContainsString($tenantB->subdomain, $manifest['scope']);
    }

    public function test_manifest_requires_no_authentication(): void
    {
        // The manifest/service-worker must be fetchable by the browser from
        // the (unauthenticated) tenant login page itself, otherwise a
        // visitor can never discover/install the PWA before logging in —
        // see the 'panel/manifest.json' route comment in routes/web.php.
        $tenant = $this->makeTenant();

        $response = $this->get($this->panelUrl($tenant, 'manifest.json'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();
        $this->assertStringContainsString($tenant->subdomain, $manifest['start_url']);
    }

    public function test_service_worker_requires_no_authentication(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->panelUrl($tenant, 'sw.js'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }

    public function test_dashboard_still_requires_authentication(): void
    {
        // Guards against the fix that made manifest.json/sw.js public
        // accidentally weakening auth on any other panel route.
        $tenant = $this->makeTenant();

        $response = $this->get('/shop/'.$tenant->subdomain.'/panel');

        $response->assertRedirect();
        $response->assertRedirectContains('login');
    }

    public function test_service_worker_resolves_with_correct_content_type_and_scope_safe_body(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'sw.js'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('CACHE_NAME', $body);
        $this->assertStringContainsString('/build/', $body);

        // Security-critical: pull the actual APP_SHELL array out of the
        // generated script (not the whole body, which legitimately
        // mentions page names like "Orders"/"Messenger" in its own
        // explanatory comments) and assert every URL in it is a static
        // build/icon asset — never a page route, an API path, or anything
        // that could resolve to authenticated/private data.
        $this->assertMatchesRegularExpression('/const APP_SHELL = (\[.*?\]);/', $body);
        preg_match('/const APP_SHELL = (\[.*?\]);/', $body, $matches);
        $shellUrls = json_decode($matches[1], true);

        $this->assertNotEmpty($shellUrls);
        foreach ($shellUrls as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            $this->assertTrue(
                str_starts_with($path, '/build/') || str_starts_with($path, '/images/icons/'),
                "precached URL must be a static build/icon asset, got: $url"
            );
        }

        // No literal secret-shaped value anywhere in the generated script.
        foreach (['password', 'access_token', 'api_key', 'secret_key'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body, "service worker script must never reference \"$needle\"");
        }
    }

    public function test_service_worker_fetch_handler_only_intercepts_get_and_only_static_paths(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $body = $this->actingAs($user, 'tenant')
            ->get($this->panelUrl($tenant, 'sw.js'))
            ->getContent();

        $this->assertStringContainsString("if (req.method !== 'GET') return;", $body);
        $this->assertStringContainsString("startsWith('/build/')", $body);
        $this->assertStringContainsString("startsWith('/images/icons/')", $body);
    }

    /**
     * The login page used to @extend('layouts.central'), which pointed
     * the browser at the CENTRAL manifest/service-worker — installing from
     * the login screen would then launch the central marketing site, not
     * this tenant's panel. Now @extends('layouts.tenant-auth'), which uses
     * the tenant.pwa.* routes instead — this locks that in.
     */
    public function test_login_page_references_the_tenant_manifest_and_service_worker_not_the_central_ones(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->panelUrl($tenant, 'login'));

        $response->assertOk();
        $html = $response->getContent();

        // window.__swUrl is emitted via Blade's @js(), which JSON-escapes
        // forward slashes ("/" -> "\/") — normalize both the haystack and
        // the expected URL the same way before comparing.
        $unescape = fn (string $s) => str_replace('\\/', '/', $s);

        $this->assertStringContainsString($this->panelUrl($tenant, 'manifest.json'), $html);
        $this->assertStringContainsString($this->panelUrl($tenant, 'sw.js'), $unescape($html));
        $this->assertStringNotContainsString('"'.route('central.pwa.manifest').'"', $html);
        $this->assertStringNotContainsString(route('central.pwa.sw'), $unescape($html));
    }
}
