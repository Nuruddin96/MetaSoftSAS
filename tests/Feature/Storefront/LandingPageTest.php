<?php

namespace Tests\Feature\Storefront;

use App\Models\Inventory;
use App\Models\LandingPage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Single Product Landing Page Builder (Phase 2) — draft/publish visibility,
 * the product it's permanently bound to, and that ordering through it
 * reuses the same variant resolution + stock enforcement as the regular
 * product page (via OrderPlacementService), not a second copy of either.
 */
class LandingPageTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        DB::table('bd_divisions')->insert([
            ['id' => 1, 'name' => 'Barishal', 'bn_name' => 'বরিশাল'],
            ['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা'],
        ]);
        DB::table('bd_districts')->insert(['id' => 1, 'division_id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
    }

    protected function storeUrl(Tenant $tenant, string $path = ''): string
    {
        return '/shop/'.$tenant->subdomain.($path ? '/'.$path : '');
    }

    protected function panelUrl(Tenant $tenant, string $path = ''): string
    {
        return '/shop/'.$tenant->subdomain.'/panel'.($path ? '/'.$path : '');
    }

    protected function makeLandingPage(Tenant $tenant, array $overrides = []): LandingPage
    {
        app()->instance('currentTenant', $tenant);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Brilliant Skin Set', 'is_active' => 1]);
        $warehouse = Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);
        $variant = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 1200, 'purchase_price' => 700,
        ]);
        Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

        return LandingPage::create(array_merge([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'title' => 'Brilliant Skin Offer',
            'status' => 'draft',
            'sections' => LandingPage::defaultSections($product),
        ], $overrides));
    }

    // --- Visibility --------------------------------------------------------

    public function test_a_draft_landing_page_404s_for_a_public_visitor(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant);

        $this->get($this->storeUrl($tenant, 'l/'.$lp->slug))->assertNotFound();
    }

    public function test_a_draft_landing_page_is_visible_to_the_logged_in_tenant_owner_as_a_preview(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $lp = $this->makeLandingPage($tenant);

        $response = $this->actingAs($user, 'tenant')->get($this->storeUrl($tenant, 'l/'.$lp->slug));

        $response->assertOk();
        $response->assertSee('Brilliant Skin Set');
    }

    public function test_a_published_landing_page_is_visible_to_everyone_and_shows_checkout(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant, ['status' => 'published', 'published_at' => now()]);

        $response = $this->get($this->storeUrl($tenant, 'l/'.$lp->slug));

        $response->assertOk();
        $response->assertSee('Brilliant Skin Set');
        $response->assertSee(route('storefront.landing.order', $lp->slug), false);
    }

    public function test_a_published_landing_page_never_leaks_to_another_tenants_slug_lookup(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $lp = $this->makeLandingPage($tenantA, ['status' => 'published', 'published_at' => now()]);

        // Same slug requested under tenant B's own storefront must not resolve tenant A's page.
        $this->get($this->storeUrl($tenantB, 'l/'.$lp->slug))->assertNotFound();
    }

    /**
     * Storefront\LandingPageController::findVisible() eager-loads
     * `product.variants` — must be scoped to is_active=1 the same way
     * Storefront\ProductController::show() already scopes its own, or an
     * inactive variant's attribute value leaks into the checkout widget as
     * a selectable option (product-buy-widget.blade.php regression guard).
     */
    public function test_an_inactive_variants_attribute_value_never_appears_on_the_landing_page(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant, ['status' => 'published', 'published_at' => now()]);

        app()->instance('currentTenant', $tenant);
        $activeSecond = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $lp->product_id,
            'variant_name' => 'Small', 'attributes' => ['size' => 'Small'], 'selling_price' => 900,
        ]);
        $inactive = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $lp->product_id,
            'variant_name' => 'Large', 'attributes' => ['size' => 'Large'],
            'selling_price' => 1500, 'is_active' => 0,
        ]);
        // The bound product's default (first, un-attributed) variant also
        // needs the same attribute key so optionAxes() treats these three
        // as one consistent axis instead of bailing out to no picker.
        $lp->product->variants->first()->update(['attributes' => ['size' => 'Regular']]);

        $response = $this->get($this->storeUrl($tenant, 'l/'.$lp->slug));

        $response->assertOk();
        $response->assertSee('data-value="Small"', false);
        $response->assertDontSee('data-value="Large"', false);
        $this->assertNotNull($activeSecond);
        $this->assertNotNull($inactive);
    }

    public function test_a_crafted_order_for_an_inactive_variant_is_refused(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant, ['status' => 'published', 'published_at' => now()]);
        $variant = $lp->product->variants->first();
        $variant->update(['is_active' => 0]);

        $response = $this->post($this->storeUrl($tenant, 'l/'.$lp->slug.'/order'), [
            'variant_id' => $variant->id,
            'customer_name' => 'Karim',
            'customer_phone' => '01711112222',
            'customer_address' => 'House 1, Road 2, Dhaka',
            'division_id' => 3,
            'district_id' => 1,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    // --- Ordering ------------------------------------------------------------

    public function test_placing_an_order_from_a_published_landing_page_creates_the_exact_order(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant, ['status' => 'published', 'published_at' => now()]);
        $variant = $lp->product->variants->first();

        $response = $this->post($this->storeUrl($tenant, 'l/'.$lp->slug.'/order'), [
            'variant_id' => $variant->id,
            'qty' => 2,
            'customer_name' => 'Karim',
            'customer_phone' => '01711112222',
            'customer_address' => 'House 1, Road 2, Dhaka',
            'division_id' => 3,
            'district_id' => 1,
        ]);

        $order = Order::first();
        $this->assertNotNull($order);
        $response->assertRedirect(route('storefront.order.success', $order->order_number));
        $this->assertSame(2, $order->items()->first()->quantity);
        $this->assertSame($variant->id, $order->items()->first()->variant_id);
        $this->assertEquals(1200 * 2, (float) $order->subtotal);

        // Stock actually decremented — same enforcement as the regular checkout.
        $this->assertSame(8, (int) Inventory::where('variant_id', $variant->id)->sum('quantity'));
    }

    public function test_a_variant_from_a_different_product_cannot_be_ordered_through_this_landing_page(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant, ['status' => 'published', 'published_at' => now()]);

        app()->instance('currentTenant', $tenant);
        $otherProduct = Product::create(['tenant_id' => $tenant->id, 'name' => 'Unrelated Product', 'is_active' => 1]);
        $otherVariant = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $otherProduct->id,
            'variant_name' => 'Default', 'selling_price' => 300,
        ]);

        $response = $this->post($this->storeUrl($tenant, 'l/'.$lp->slug.'/order'), [
            'variant_id' => $otherVariant->id,
            'customer_name' => 'Karim',
            'customer_phone' => '01711112222',
            'customer_address' => 'House 1, Road 2, Dhaka',
            'division_id' => 3,
            'district_id' => 1,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    public function test_ordering_more_than_available_stock_is_refused(): void
    {
        $tenant = $this->makeTenant();
        $lp = $this->makeLandingPage($tenant, ['status' => 'published', 'published_at' => now()]);
        $variant = $lp->product->variants->first();

        $response = $this->post($this->storeUrl($tenant, 'l/'.$lp->slug.'/order'), [
            'variant_id' => $variant->id,
            'qty' => 50,
            'customer_name' => 'Karim',
            'customer_phone' => '01711112222',
            'customer_address' => 'House 1, Road 2, Dhaka',
            'division_id' => 3,
            'district_id' => 1,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    // --- Tenant panel builder --------------------------------------------------

    public function test_tenant_can_create_a_landing_page_with_default_sections(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Face Serum', 'is_active' => 1]);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'landing-pages'), [
            'title' => 'Face Serum Offer',
            'product_id' => $product->id,
        ]);

        $lp = LandingPage::first();
        $response->assertRedirect(route('tenant.landing-pages.edit', $lp));
        $this->assertSame('draft', $lp->status);
        $this->assertSame($product->id, $lp->product_id);
        $this->assertNotEmpty($lp->sections);
        $this->assertContains('checkout', array_column($lp->sections, 'type'));
    }

    public function test_tenant_panel_index_and_builder_pages_render(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $lp = $this->makeLandingPage($tenant);

        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'landing-pages'))->assertOk()->assertSee('Brilliant Skin Offer');
        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'landing-pages/create'))->assertOk();
        $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, "landing-pages/{$lp->id}/edit"))->assertOk()->assertSee('চেকআউট / অর্ডার ফর্ম');
    }

    public function test_tenant_can_add_edit_and_publish_a_section(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $lp = $this->makeLandingPage($tenant);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, "landing-pages/{$lp->id}/sections"), ['type' => 'faq']);
        $lp->refresh();
        $faqSection = collect($lp->sections)->firstWhere('type', 'faq');
        $this->assertNotNull($faqSection);

        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "landing-pages/{$lp->id}/sections/{$faqSection['id']}"), [
            'data' => ['heading' => 'প্রশ্নোত্তর', 'items' => [['question' => 'ডেলিভারি কতদিনে?', 'answer' => '৩-৫ দিন']]],
        ]);
        $lp->refresh();
        $saved = collect($lp->sections)->firstWhere('id', $faqSection['id']);
        $this->assertSame('প্রশ্নোত্তর', $saved['data']['heading']);
        $this->assertSame('ডেলিভারি কতদিনে?', $saved['data']['items'][0]['question']);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, "landing-pages/{$lp->id}/publish"));
        $lp->refresh();
        $this->assertTrue($lp->isPublished());

        $this->get($this->storeUrl($tenant, 'l/'.$lp->slug))->assertOk()->assertSee('ডেলিভারি কতদিনে?');
    }
}
