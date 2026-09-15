<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\StoreSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Smoke coverage for the storefront redesign — home/listing/product pages
 * previously had NO automated coverage at all, so a Blade error (missing
 * variable, wrong relation name) would only surface by someone actually
 * loading the page. Not a full storefront test suite; just enough to prove
 * every touched view still renders correctly with real data, across the
 * new discount/stock/gallery display this redesign added.
 */
class StorefrontRenderTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function storeUrl(Tenant $tenant, string $path = ''): string
    {
        return '/shop/'.$tenant->subdomain.($path ? '/'.$path : '');
    }

    public function test_home_page_renders_with_no_products(): void
    {
        $tenant = $this->makeTenant();

        $this->get($this->storeUrl($tenant))->assertOk();
    }

    public function test_home_page_renders_with_a_real_product_and_trust_strip(): void
    {
        $tenant = $this->makeTenant();
        $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertSee('500', false);
        $response->assertSee('ক্যাশ অন ডেলিভারি');
    }

    public function test_products_listing_renders_and_paginates(): void
    {
        $tenant = $this->makeTenant();
        $this->makeSellableVariant($tenant->id);

        $this->get($this->storeUrl($tenant, 'products'))->assertOk();
    }

    public function test_products_listing_filters_by_category(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Skincare', 'slug' => 'skincare', 'is_active' => 1]);
        $this->makeSellableVariant($tenant->id);

        $this->get($this->storeUrl($tenant, 'products?category='.$category->slug))->assertOk();
    }

    /** New `q` name-search param (storefront redesign) — additive to ProductController::index(), must not match an unrelated product. */
    public function test_products_listing_search_filters_by_name(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $this->makeSellableVariant($tenant->id); // "Test Product"
        $other = \App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => 'Unrelated Widget', 'is_active' => 1]);

        $response = $this->get($this->storeUrl($tenant, 'products?q=Test'));

        $response->assertOk();
        $response->assertSee('Test Product');
        $response->assertDontSee('Unrelated Widget');
    }

    /** New `sort=price_asc`/`price_desc` param (storefront redesign) — sorts by each product's cheapest active variant. */
    public function test_products_listing_sorts_by_price(): void
    {
        $tenant = $this->makeTenant();
        $cheap = $this->makeSellableVariant($tenant->id, ['selling_price' => 100]);
        app()->instance('currentTenant', $tenant);
        $expensiveProduct = \App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => 'Pricey Item', 'is_active' => 1]);
        \App\Models\ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $expensiveProduct->id,
            'variant_name' => 'Default', 'selling_price' => 900, 'purchase_price' => 500,
        ]);

        $response = $this->get($this->storeUrl($tenant, 'products?sort=price_asc'));

        $response->assertOk();
        $cheapPos = strpos($response->getContent(), $cheap->product->name);
        $pricePos = strpos($response->getContent(), 'Pricey Item');
        $this->assertNotFalse($cheapPos);
        $this->assertNotFalse($pricePos);
        $this->assertLessThan($pricePos, $cheapPos, 'cheapest product should render before the pricier one under price_asc sort');
    }

    /** Proves the new discount badge, compare-at price, and stock line all render from real data, not just "the page didn't crash". */
    public function test_product_detail_page_shows_discount_and_stock(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, [
            'selling_price' => 450,
            'compare_at_price' => 600,
        ]);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$variant->product->slug));

        $response->assertOk();
        $response->assertSee('450', false);
        $response->assertSee('600', false);
        $response->assertSee('Save 150 Tk', false);
        $response->assertSee('স্টকে আছে');
    }

    public function test_product_detail_page_shows_gallery_images(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);
        app()->instance('currentTenant', $tenant);
        $variant->product->images()->create(['image_path' => 'products/extra.jpg', 'sort_order' => 1]);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$variant->product->slug));

        $response->assertOk();
        $response->assertSee('thumb-btn', false);
    }

    public function test_product_detail_page_shows_out_of_stock_state(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);
        Inventory::where('variant_id', $variant->id)->update(['quantity' => 0]);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$variant->product->slug));

        $response->assertOk();
        $response->assertSee('স্টক শেষ');
    }

    public function test_unknown_product_slug_404s(): void
    {
        $tenant = $this->makeTenant();

        $this->get($this->storeUrl($tenant, 'product/does-not-exist'))->assertNotFound();
    }

    /** Tenant isolation: tenant A's product must never resolve under tenant B's storefront URL. */
    public function test_a_products_page_never_shows_another_tenants_products(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        // makeSellableVariant() always names the product "Test Product" —
        // distinguish tenant A's row by its price instead, since both
        // tenants' products otherwise share that same default name.
        $this->makeSellableVariant($tenantA->id, ['selling_price' => 999]);
        $this->makeSellableVariant($tenantB->id, ['selling_price' => 111]);

        $response = $this->get($this->storeUrl($tenantB, 'products'));

        $response->assertOk();
        $response->assertDontSee('999৳');
    }

    public function test_cart_and_checkout_routes_are_unaffected(): void
    {
        $tenant = $this->makeTenant();

        $this->get($this->storeUrl($tenant, 'cart'))->assertOk();
        // Unchanged existing behavior: an empty cart bounces checkout back
        // to the homepage rather than rendering — not something this
        // redesign touched, just confirming it's still true.
        $this->get($this->storeUrl($tenant, 'checkout'))->assertRedirect($this->storeUrl($tenant));
    }

    /**
     * The checkout page with real cart items was never actually rendered by
     * any prior test (only the empty-cart redirect was). Added alongside the
     * storefront redesign to prove the numbered-step layout actually
     * compiles and still exposes every field/id the existing division→
     * district cascading and incomplete-order-tracking JS depends on.
     */
    public function test_checkout_page_renders_with_items_in_cart(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        $this->withSession(['cart_'.$tenant->id => [$variant->id => 2]]);

        $response = $this->get($this->storeUrl($tenant, 'checkout'));

        $response->assertOk();
        $response->assertSee('গ্রাহকের তথ্য');
        $response->assertSee('ডেলিভারি তথ্য');
        $response->assertSee('পেমেন্ট পদ্ধতি');
        $response->assertSee('ক্যাশ অন ডেলিভারি (COD)');
        $response->assertSee('id="divisionSelect"', false);
        $response->assertSee('id="districtSelect"', false);
        $response->assertSee('id="chargeShow"', false);
        $response->assertSee('id="totalShow"', false);
        $response->assertSee('1,000৳'); // 500 × 2 subtotal
    }

    /** The order-success page was likewise never rendered by an existing test. */
    public function test_order_success_page_renders(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        Inventory::where('variant_id', $variant->id)->update(['quantity' => 10]);
        $this->withSession(['cart_'.$tenant->id => [$variant->id => 1]]);

        DB::table('bd_divisions')->insert(['id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
        DB::table('bd_districts')->insert(['id' => 1, 'division_id' => 3, 'name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $this->post($this->storeUrl($tenant, 'checkout'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'customer_address' => 'Some address', 'division_id' => 3, 'district_id' => 1,
        ]);

        $order = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $response = $this->get($this->storeUrl($tenant, 'order-success/'.$order->order_number));

        $response->assertOk();
        $response->assertSee('অর্ডার কনফার্মড');
        $response->assertSee($order->order_number);
    }

    // --- Offer section (redesigned: plain grid, capped to 2, no marquee) -----------------------

    public function test_homepage_offer_section_is_hidden_when_nothing_is_discounted(): void
    {
        $tenant = $this->makeTenant();
        $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertDontSee('অফার পন্য');
    }

    public function test_homepage_offer_section_shows_at_most_four_products_with_a_see_all_link(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        // Featured section off — isolates the offer section so counting its
        // cards below isn't muddied by the same discounted products also
        // appearing in the regular "আমাদের প্রোডাক্ট" grid.
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'show_featured', 'value' => '0']);
        // Five distinct discounted products — the section must cap to 4
        // (desktop grid-cols-4, mobile grid-cols-2).
        foreach ([100, 200, 300, 400, 500] as $price) {
            $this->makeSellableVariant($tenant->id, ['selling_price' => $price, 'compare_at_price' => $price + 50]);
        }

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertSee('অফার পন্য');
        $response->assertSee(route('storefront.products', ['offer' => 1]), false);
        // No auto-sliding marquee chrome from the old carousel design.
        $response->assertDontSee('offer-marquee', false);
        $this->assertSame(4, substr_count($response->getContent(), 'Save 50 Tk'));
    }

    public function test_offer_filter_on_products_listing_shows_only_discounted_products(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $this->makeSellableVariant($tenant->id, ['selling_price' => 111, 'compare_at_price' => 200]);
        $this->makeSellableVariant($tenant->id, ['selling_price' => 999]); // no offer

        $response = $this->get($this->storeUrl($tenant, 'products?offer=1'));

        $response->assertOk();
        $response->assertSee('111৳', false);
        $response->assertDontSee('999৳', false);
    }

    /** Tenant isolation: the ?offer=1 filter must never leak another tenant's discounted product. */
    public function test_offer_filter_never_shows_another_tenants_discounted_product(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeSellableVariant($tenantA->id, ['selling_price' => 777, 'compare_at_price' => 900]);
        $this->makeSellableVariant($tenantB->id, ['selling_price' => 222, 'compare_at_price' => 300]);

        $response = $this->get($this->storeUrl($tenantB, 'products?offer=1'));

        $response->assertOk();
        $response->assertSee('222৳', false);
        $response->assertDontSee('777৳', false);
    }

    // --- Microsoft Clarity (tenant-scoped, marketing_settings.clarity_project_id) --------------

    public function test_storefront_head_includes_clarity_script_when_project_id_is_set(): void
    {
        $tenant = $this->makeTenant();
        DB::table('marketing_settings')->insert([
            'tenant_id' => $tenant->id,
            'clarity_project_id' => 'abc123xyz9',
            'updated_at' => now(),
        ]);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        // The official snippet builds the request URL via JS concatenation
        // (t.src=".../tag/"+i), so the literal id only ever appears as its
        // own quoted argument in the static markup, never joined with the URL.
        $response->assertSee('clarity.ms/tag/', false);
        $response->assertSee('"clarity", "script", "abc123xyz9"', false);
    }

    public function test_storefront_head_omits_clarity_script_when_project_id_is_null(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertDontSee('clarity.ms/tag', false);
    }

    /** Tenant isolation: tenant A's Clarity Project ID must never render on tenant B's storefront. */
    public function test_storefront_never_leaks_another_tenants_clarity_project_id(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        DB::table('marketing_settings')->insert([
            'tenant_id' => $tenantA->id,
            'clarity_project_id' => 'tenant-a-id',
            'updated_at' => now(),
        ]);

        $response = $this->get($this->storeUrl($tenantB));

        $response->assertOk();
        $response->assertDontSee('tenant-a-id', false);
    }
}
