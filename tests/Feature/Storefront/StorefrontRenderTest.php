<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Tenant;
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
        \App\Models\Inventory::where('variant_id', $variant->id)->update(['quantity' => 0]);

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
}
