<?php

namespace Tests\Feature\Storefront;

use App\Models\FacebookPage;
use App\Models\Inventory;
use App\Models\MessengerSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Product detail ordering + variant selection + stock protection + the
 * WhatsApp/Messenger contact resolution — the actual backend enforcement
 * behind this task's requirements, not just that a page renders.
 */
class StorefrontOrderingTest extends TestCase
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

    /** Two variants for the same product — matches the real "Color/Size" shape (attributes JSON, variant_name label). */
    protected function makeTwoVariantProduct(Tenant $tenant, array $variantAOverrides = [], array $variantBOverrides = []): array
    {
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Premium Shirt', 'is_active' => 1]);
        $warehouse = Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);

        $variantA = ProductVariant::create(array_merge([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Red / M', 'attributes' => ['color' => 'Red', 'size' => 'M'],
            'selling_price' => 800, 'purchase_price' => 500,
        ], $variantAOverrides));
        Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $variantA->id, 'warehouse_id' => $warehouse->id, 'quantity' => $variantAOverrides['_stock'] ?? 10]);

        $variantB = ProductVariant::create(array_merge([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Blue / L', 'attributes' => ['color' => 'Blue', 'size' => 'L'],
            'selling_price' => 850, 'purchase_price' => 500,
        ], $variantBOverrides));
        Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $variantB->id, 'warehouse_id' => $warehouse->id, 'quantity' => $variantBOverrides['_stock'] ?? 10]);

        return [$product, $variantA, $variantB];
    }

    // --- 1. Order button + simple product flow ---------------------------------------------------

    public function test_order_button_is_visible_on_the_product_page(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$variant->product->slug));

        $response->assertOk();
        $response->assertSee('অর্ডার করুন');
        $response->assertSee(route('storefront.cart.add'), false);
    }

    public function test_a_simple_product_with_no_variants_can_be_ordered(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);

        $this->post($this->storeUrl($tenant, 'cart/add'), ['variant_id' => $variant->id, 'qty' => 1])
            ->assertRedirect($this->storeUrl($tenant, 'cart'));

        $cart = $this->app['session']->get('cart_'.$tenant->id);
        $this->assertSame(1, $cart[$variant->id]);
    }

    // --- 2. Variant selection reaches cart correctly ----------------------------------------------

    public function test_product_page_shows_both_configured_variants_with_their_own_price(): void
    {
        $tenant = $this->makeTenant();
        [$product, $variantA, $variantB] = $this->makeTwoVariantProduct($tenant);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$product->slug));

        $response->assertOk();
        $response->assertSee('Red / M');
        $response->assertSee('Blue / L');
        $response->assertSee('800', false);
    }

    public function test_the_exact_selected_variant_id_and_price_reach_the_cart(): void
    {
        $tenant = $this->makeTenant();
        [$product, $variantA, $variantB] = $this->makeTwoVariantProduct($tenant);

        $this->post($this->storeUrl($tenant, 'cart/add'), ['variant_id' => $variantB->id, 'qty' => 2])
            ->assertRedirect($this->storeUrl($tenant, 'cart'));

        $cart = $this->app['session']->get('cart_'.$tenant->id);
        $this->assertArrayNotHasKey($variantA->id, $cart);
        $this->assertSame(2, $cart[$variantB->id]);
    }

    // --- 3. Out-of-stock protection ------------------------------------------------------------

    public function test_an_out_of_stock_variant_shows_stock_out_state_on_the_page(): void
    {
        $tenant = $this->makeTenant();
        [$product] = $this->makeTwoVariantProduct($tenant, [], ['_stock' => 0]);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$product->slug));

        $response->assertOk();
        $response->assertSee('data-stock="0"', false);
    }

    public function test_the_backend_refuses_to_add_an_out_of_stock_variant_to_the_cart(): void
    {
        $tenant = $this->makeTenant();
        [$product, $variantA, $variantB] = $this->makeTwoVariantProduct($tenant, [], ['_stock' => 0]);

        $response = $this->post($this->storeUrl($tenant, 'cart/add'), ['variant_id' => $variantB->id, 'qty' => 1]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $cart = $this->app['session']->get('cart_'.$tenant->id, []);
        $this->assertArrayNotHasKey($variantB->id, $cart);
    }

    public function test_requesting_more_than_available_stock_is_clamped_not_rejected_outright(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        Inventory::where('variant_id', $variant->id)->update(['quantity' => 3]);

        $this->post($this->storeUrl($tenant, 'cart/add'), ['variant_id' => $variant->id, 'qty' => 10])
            ->assertRedirect($this->storeUrl($tenant, 'cart'));

        $cart = $this->app['session']->get('cart_'.$tenant->id);
        $this->assertSame(3, $cart[$variant->id]);
    }

    /** The frontend disables the button for the CURRENT variant, but the exact same request forged for a different, out-of-stock variant must still be rejected server-side. */
    public function test_a_crafted_request_cannot_bypass_frontend_stock_protection(): void
    {
        $tenant = $this->makeTenant();
        [$product, $variantA, $variantB] = $this->makeTwoVariantProduct($tenant, ['_stock' => 5], ['_stock' => 0]);

        // A customer looking at variantA's in-stock page, but submitting variantB's id directly.
        $response = $this->post($this->storeUrl($tenant, 'cart/add'), ['variant_id' => $variantB->id, 'qty' => 1]);

        $response->assertSessionHas('error');
    }

    // --- 4. Tenant isolation on cart/checkout ---------------------------------------------------

    public function test_a_variant_id_belonging_to_another_tenant_is_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $variantA = $this->makeSellableVariant($tenantA->id);

        // Customer is browsing tenant B's storefront, but submits tenant A's real variant id.
        $response = $this->post($this->storeUrl($tenantB, 'cart/add'), ['variant_id' => $variantA->id, 'qty' => 1]);

        $response->assertSessionHasErrors('variant_id');
        $cart = $this->app['session']->get('cart_'.$tenantB->id, []);
        $this->assertArrayNotHasKey($variantA->id, $cart);
    }

    public function test_checkout_never_creates_an_order_from_another_tenants_cart_entry(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $variantA = $this->makeSellableVariant($tenantA->id, ['selling_price' => 999]);
        $variantB = $this->makeSellableVariant($tenantB->id, ['selling_price' => 111]);

        // Simulate a session cart that somehow contains a foreign tenant's variant id
        // alongside a genuine one for tenant B (defense-in-depth check, not just the add() gate).
        $this->withSession(['cart_'.$tenantB->id => [$variantA->id => 1, $variantB->id => 1]]);

        $response = $this->post($this->storeUrl($tenantB, 'checkout'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'customer_address' => 'Some address', 'division_id' => 3, 'district_id' => 1,
        ]);

        // variants->count() (1, only B's own) !== count($cart) (2) — checkout refuses rather than silently order only half.
        $response->assertRedirect();
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    public function test_checkout_rejects_placing_an_order_that_exceeds_current_stock(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        Inventory::where('variant_id', $variant->id)->update(['quantity' => 1]);

        // Bypass the add()-time clamp to simulate stock dropping between add-to-cart and checkout.
        $this->withSession(['cart_'.$tenant->id => [$variant->id => 5]]);

        $response = $this->post($this->storeUrl($tenant, 'checkout'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'customer_address' => 'Some address', 'division_id' => 3, 'district_id' => 1,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Inventory::where('variant_id', $variant->id)->value('quantity'), 'stock must never go negative or be decremented on a rejected order');
    }

    public function test_checkout_succeeds_and_decrements_stock_when_everything_is_valid(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id, ['selling_price' => 500]);
        Inventory::where('variant_id', $variant->id)->update(['quantity' => 10]);
        $this->withSession(['cart_'.$tenant->id => [$variant->id => 2]]);

        $response = $this->post($this->storeUrl($tenant, 'checkout'), [
            'customer_name' => 'Karim', 'customer_phone' => '01712345678',
            'customer_address' => 'Some address', 'division_id' => 3, 'district_id' => 1,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(8, Inventory::where('variant_id', $variant->id)->value('quantity'));
    }

    // --- 5. WhatsApp / Messenger contact resolution -----------------------------------------------

    public function test_whatsapp_contact_link_renders_when_explicitly_enabled(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'show_whatsapp_float', 'value' => '1']);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'whatsapp_number', 'value' => '8801711223344']);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertSee('https://wa.me/8801711223344', false);
    }

    public function test_messenger_contact_link_renders_when_whatsapp_is_not_enabled_but_messenger_is_connected(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        MessengerSetting::create(['tenant_id' => $tenant->id, 'page_id' => '123456789', 'page_access_token' => 'x', 'is_active' => 1]);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertSee('https://m.me/123456789', false);
    }

    public function test_whatsapp_wins_over_messenger_when_both_are_configured(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'show_whatsapp_float', 'value' => '1']);
        StoreSetting::create(['tenant_id' => $tenant->id, 'key' => 'whatsapp_number', 'value' => '8801711223344']);
        MessengerSetting::create(['tenant_id' => $tenant->id, 'page_id' => '123456789', 'page_access_token' => 'x', 'is_active' => 1]);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertSee('https://wa.me/8801711223344', false);
        $response->assertDontSee('https://m.me/123456789', false);
    }

    public function test_no_contact_channel_configured_never_fabricates_a_link(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $response->assertDontSee('wa.me', false);
        $response->assertDontSee('m.me', false);
    }

    public function test_another_tenants_whatsapp_number_never_appears_on_a_different_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        StoreSetting::create(['tenant_id' => $tenantA->id, 'key' => 'show_whatsapp_float', 'value' => '1']);
        StoreSetting::create(['tenant_id' => $tenantA->id, 'key' => 'whatsapp_number', 'value' => '8801711223344']);

        $response = $this->get($this->storeUrl($tenantB));

        $response->assertDontSee('8801711223344');
    }

    // --- 6. Mobile bottom nav ------------------------------------------------------------------

    public function test_mobile_bottom_nav_has_exactly_five_items_on_the_home_page(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'aria-label="মোবাইল নেভিগেশন"'));
        $this->assertSame(5, substr_count($html, 'text-[10px] leading-none'));
    }

    public function test_mobile_bottom_nav_is_absent_on_the_product_detail_page(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);

        $response = $this->get($this->storeUrl($tenant, 'product/'.$variant->product->slug));

        $response->assertOk();
        $response->assertDontSee('মোবাইল নেভিগেশন');
    }

    public function test_mobile_bottom_nav_links_point_to_real_existing_routes(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->get($this->storeUrl($tenant));

        $response->assertSee(route('storefront.products'), false);
        $response->assertSee(route('storefront.home'), false);
        $response->assertSee(route('storefront.cart'), false);
    }

    // --- 7. Homepage layout: category section removed, trust strip moved -----------------------

    public function test_home_page_no_longer_shows_the_category_section_after_the_banner(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $category = \App\Models\Category::create(['tenant_id' => $tenant->id, 'name' => 'Skincare', 'slug' => 'skincare', 'is_active' => 1]);
        $this->makeSellableVariant($tenant->id);

        $response = $this->get($this->storeUrl($tenant));

        $response->assertOk();
        // "ক্যাটাগরি" alone would also match the (intentional, separate)
        // bottom-nav label — assert the actual removed section instead:
        // the per-category pill link the homepage used to render.
        $response->assertDontSee(route('storefront.products', ['category' => $category->slug]), false);
        $response->assertDontSee('Skincare');
    }

    public function test_products_listing_still_has_its_own_category_filter(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $category = \App\Models\Category::create(['tenant_id' => $tenant->id, 'name' => 'Skincare', 'slug' => 'skincare', 'is_active' => 1]);

        $response = $this->get($this->storeUrl($tenant, 'products'));

        $response->assertOk();
        $response->assertSee('Skincare');
    }

    public function test_trust_strip_renders_after_the_product_section_not_before(): void
    {
        $tenant = $this->makeTenant();
        $this->makeSellableVariant($tenant->id);

        $response = $this->get($this->storeUrl($tenant));
        $html = $response->getContent();

        $productsHeadingPos = strpos($html, 'আমাদের প্রোডাক্ট');
        $trustBoxPos = strpos($html, 'ক্যাশ অন ডেলিভারি');

        $this->assertNotFalse($productsHeadingPos);
        $this->assertNotFalse($trustBoxPos);
        $this->assertGreaterThan($productsHeadingPos, $trustBoxPos, 'trust strip must render after the product section, not before it');
    }
}
