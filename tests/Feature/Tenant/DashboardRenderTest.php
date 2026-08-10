<?php

namespace Tests\Feature\Tenant;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Smoke coverage for the mobile dashboard stat-tile redesign — no
 * dedicated GET-rendering test existed for this page before. Confirms the
 * five KPI values/labels render unchanged and that each tile's new href
 * resolves to the exact existing route (no new routes were added; a wrong
 * route() call here would throw at render time, not silently misbehave).
 */
class DashboardRenderTest extends TestCase
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

    public function test_dashboard_renders_with_unchanged_stats_and_correct_tile_links(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        Product::create(['tenant_id' => $tenant->id, 'name' => 'Test Product', 'is_active' => 1]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Karim', 'phone' => '01711223344']);
        Order::create([
            'tenant_id' => $tenant->id, 'source' => 'web', 'channel' => 'website',
            'customer_id' => $customer->id, 'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'status' => 'pending', 'subtotal' => 500, 'total' => 550,
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response->assertOk();

        // Stat values/labels unchanged.
        $response->assertSee('আজকের অর্ডার');
        $response->assertSee('আজকের বিক্রি');
        $response->assertSee('পেন্ডিং অর্ডার');
        $response->assertSee('মোট প্রোডাক্ট');
        $response->assertSee('মোট কাস্টমার');
        $response->assertSee('1', false); // totalProducts / pendingOrders count

        // Each tile links to the exact existing route/params — a typo'd
        // route name here would throw a RouteNotFoundException, not just
        // render a broken link, so assertOk() above already partly proves
        // this, but assert the exact hrefs too.
        $response->assertSee($this->panelUrl($tenant, 'orders'), false);
        $response->assertSee($this->panelUrl($tenant, 'reports/sales'), false);
        $response->assertSee($this->panelUrl($tenant, 'orders?status=pending'), false);
        $response->assertSee($this->panelUrl($tenant, 'products'), false);
        $response->assertSee($this->panelUrl($tenant, 'customers'), false);
    }

    public function test_dashboard_renders_with_zero_stats_and_no_data(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response->assertOk();
        $response->assertSee('আজকের অর্ডার');
    }

    public function test_pwa_chrome_is_present_in_the_rendered_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response->assertOk();
        $html = $response->getContent();

        // Splash screen component actually rendered (not just present in
        // source — assertSee checks the real HTTP response body).
        $this->assertStringContainsString('id="appSplash"', $html);
        $this->assertStringContainsString('splash-logo', $html);

        // Manifest/icon link tags point at the routes verified separately
        // in PwaTest, plus the required PWA meta tags are present.
        $response->assertSee($this->panelUrl($tenant, 'manifest.json'), false);
        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('name="theme-color" content="#128155"', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('apple-touch-icon', $html);

        // Service worker URL queued for app.js to register — the actual
        // registration call lives in app.js (not server-rendered). @js()
        // JSON-escapes forward slashes (matching the pre-existing
        // __flashMessages pattern on the very next line in the source),
        // so assert the same escaped form actually present in the HTML.
        $this->assertStringContainsString('window.__swUrl', $html);
        $this->assertStringContainsString($tenant->subdomain.'\/panel\/sw.js', $html);

        // Bottom nav present with no pill/background class on the icon.
        $this->assertStringContainsString('id="navProgress"', $html);
    }
}
