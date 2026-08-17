<?php

namespace Tests\Feature\Order;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Covers Tenant\OrderController::productsJson() — the data the New Order
 * page's client-side product search/thumbnail picker (resources/views/
 * tenant/orders/create.blade.php) is built from. The search/filter logic
 * itself is plain client-side JS (no endpoint, no page reload — see that
 * file's renderProductOptions()), so what's actually testable server-side
 * is that the JSON payload it searches over is correct and tenant-scoped:
 * real product names, variant SKUs, and thumbnail paths, never another
 * tenant's data, never inactive products.
 */
class OrderProductSelectorTest extends TestCase
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

    public function test_the_search_input_and_thumbnail_markup_are_present_on_the_order_form(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create'));

        $response->assertOk();
        $response->assertSee('প্রোডাক্ট খুঁজুন', false);
        $response->assertSee('prodSearch', false);
        $response->assertSee('prodThumb', false);
        // Client-side filter — no separate AJAX search endpoint/page reload.
        $response->assertSee('renderProductOptions', false);
    }

    public function test_products_json_includes_the_products_name_variant_sku_and_thumbnail(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'COSRX Snail Cream', 'is_active' => 1]);
        $variant = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 850, 'purchase_price' => 400,
        ]);
        ProductImage::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'image_path' => 'products/snail-cream.jpg', 'sort_order' => 1]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create'));

        $response->assertOk();
        $response->assertSee('COSRX Snail Cream', false);
        // @json() escapes forward slashes (json_encode's default) — match
        // what actually reaches the browser, not the raw DB value.
        $response->assertSee('products\/snail-cream.jpg', false);
        $response->assertSee($variant->fresh()->sku, false);
    }

    public function test_inactive_products_are_not_included_in_the_selector(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);

        Product::create(['tenant_id' => $tenant->id, 'name' => 'Discontinued Product', 'is_active' => 0]);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'orders/create'));

        $response->assertOk();
        $response->assertDontSee('Discontinued Product');
    }

    /** Tenant isolation: the New Order page's product list must never include another tenant's products. */
    public function test_another_tenants_products_never_appear_in_the_selector(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        app()->instance('currentTenant', $tenantA);
        Product::create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Secret Product', 'is_active' => 1]);

        $response = $this->actingAs($userB, 'tenant')->get($this->panelUrl($tenantB, 'orders/create'));

        $response->assertOk();
        $response->assertDontSee('Tenant A Secret Product');
    }
}
