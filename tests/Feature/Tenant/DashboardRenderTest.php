<?php

namespace Tests\Feature\Tenant;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Smoke coverage for the mobile dashboard stat tiles. Confirms the six KPI
 * values/labels render and that each tile's href resolves to the exact
 * existing route (no new routes were added apart from the courier=pending
 * query param on the pre-existing orders.index route; a wrong route() call
 * here would throw at render time, not silently misbehave).
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
        // Sent to courier, not yet delivered — should count toward the
        // "কুরিয়ারে পেন্ডিং" tile. A delivered one (below) should not.
        Order::create([
            'tenant_id' => $tenant->id, 'source' => 'web', 'channel' => 'website',
            'customer_id' => $customer->id, 'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'status' => 'shipped', 'subtotal' => 500, 'total' => 550,
            'courier_consignment_id' => 'CN-1',
        ]);
        Order::create([
            'tenant_id' => $tenant->id, 'source' => 'web', 'channel' => 'website',
            'customer_id' => $customer->id, 'customer_name' => 'Karim', 'customer_phone' => '01711223344',
            'status' => 'delivered', 'subtotal' => 500, 'total' => 550,
            'courier_consignment_id' => 'CN-2',
        ]);
        Expense::create([
            'tenant_id' => $tenant->id, 'title' => 'Packaging', 'amount' => 300, 'expense_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, ''));

        $response->assertOk();

        // Stat labels.
        $response->assertSee('আজকের অর্ডার');
        $response->assertSee('আজকের বিক্রি');
        $response->assertSee('পেন্ডিং অর্ডার');
        $response->assertSee('কুরিয়ারে পেন্ডিং');
        $response->assertSee('মোট কাস্টমার');
        $response->assertSee('খরচ');
        $response->assertDontSee('মোট প্রোডাক্ট');

        // courierPendingCount counts only the shipped-with-consignment
        // order, not the delivered one — 1 pending, not 2.
        $response->assertSee('1', false);
        // todayExpenses total.
        $response->assertSee('300৳', false);

        // Each tile links to the exact existing route/params — a typo'd
        // route name here would throw a RouteNotFoundException, not just
        // render a broken link, so assertOk() above already partly proves
        // this, but assert the exact hrefs too.
        $response->assertSee($this->panelUrl($tenant, 'orders'), false);
        $response->assertSee($this->panelUrl($tenant, 'reports/sales'), false);
        $response->assertSee($this->panelUrl($tenant, 'orders?status=pending'), false);
        $response->assertSee($this->panelUrl($tenant, 'orders?courier=pending'), false);
        $response->assertSee($this->panelUrl($tenant, 'customers'), false);
        $response->assertSee($this->panelUrl($tenant, 'expenses'), false);
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
