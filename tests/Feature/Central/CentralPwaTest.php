<?php

namespace Tests\Feature\Central;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the landing page's "Install App" button and the central (non-
 * tenant) manifest/service-worker routes backing it. Deliberately
 * separate from tests/Feature/Tenant/PwaTest.php — this exercises the
 * central domain's own installable surface, not any tenant's panel, and
 * the whole point of this feature is that the two must stay independent
 * (different scope) while sharing the same underlying service-worker
 * script (PwaServiceWorkerBuilder) rather than each having its own copy.
 */
class CentralPwaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // LandingController queries Plan::where('is_active', 1) — this is
        // the only table the page needs that isn't already provided by
        // the framework's default testing schema. No rows are seeded;
        // rendering with zero plans is the page's normal empty state.
        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('slug', 100)->unique();
                $table->decimal('price_monthly', 10, 2)->default(0);
                $table->decimal('price_yearly', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    public function test_landing_page_renders_the_install_app_button_with_exact_label(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Install App', false);
        $response->assertSee('id="pwaInstallBtn"', false);
        $response->assertSee('id="pwaInstallBanner"', false);
        $response->assertSee('id="pwaIosModal"', false);

        // The iOS instructions use the exact requested phrasing.
        $response->assertSee('Safari-এর Share button চাপুন', false);
        $response->assertSee('Add to Home Screen', false);
    }

    public function test_landing_page_existing_content_is_unchanged(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('MetaSoft BD');
        $response->assertSee(route('register'), false);
        $response->assertSee(route('central.login'), false);
        $response->assertSee(route('affiliate.register'), false);
    }

    public function test_central_manifest_resolves_and_is_scoped_to_the_central_domain_not_any_tenant(): void
    {
        $response = $this->get('/manifest.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();
        $this->assertSame('MetaSoft SaaS', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertStringNotContainsString('/shop/', $manifest['start_url']);
        $this->assertStringNotContainsString('/panel', $manifest['start_url']);
        $this->assertStringContainsString(config('app.central_domain'), $manifest['start_url']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_central_manifest_requires_no_authentication(): void
    {
        // Unlike the tenant manifest (behind auth:tenant), the landing
        // page's install surface must work for a fully anonymous visitor.
        $response = $this->get('/manifest.json');

        $response->assertOk();
    }

    public function test_central_service_worker_resolves_and_matches_the_shared_builder_output(): void
    {
        $response = $this->get('/sw.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $body = $response->getContent();
        $this->assertStringContainsString('CACHE_NAME', $body);
        $this->assertStringContainsString("startsWith('/build/')", $body);
        $this->assertStringContainsString("startsWith('/images/icons/')", $body);

        // Proves this is genuinely the same generated script as the
        // tenant route, not a hand-maintained duplicate that could drift.
        $expected = app(\App\Services\Pwa\PwaServiceWorkerBuilder::class)->build();
        $this->assertSame($expected, $body);
    }

    public function test_no_duplicate_pwa_routes_are_registered(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => $name && str_contains($name, 'pwa.'))
            ->values();

        $this->assertEqualsCanonicalizing(
            ['central.pwa.manifest', 'central.pwa.sw', 'tenant.pwa.manifest', 'tenant.pwa.sw'],
            $names->unique()->all()
        );

        // Each name appears exactly once — a real duplicate-registration
        // bug would show up as a name appearing 2+ times here.
        foreach ($names->countBy() as $name => $count) {
            $this->assertSame(1, $count, "route name \"$name\" is registered more than once");
        }
    }
}
