<?php

namespace Tests\Feature\WordPress;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Models\WordPressConnection;
use App\Services\WordPress\WordPressConnectorService;
use Illuminate\Support\Facades\Http;

class WordPressProductSyncTest extends WordPressFeatureTestCase
{
    protected function connectTenant($tenant, $user): WordPressConnection
    {
        $service = new WordPressConnectorService;
        $state = $service->createConnectionToken($tenant, $user);

        [$connection] = $service->completeHandshake($state, [
            'site_url' => 'https://example-shop.com',
            'wp_rest_url' => 'https://example-shop.com/wp-json',
        ]);

        return $connection->fresh();
    }

    protected function makeWarehouse($tenant): Warehouse
    {
        return Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => true]);
    }

    // --- product push ----------------------------------------------------------

    public function test_saving_a_product_pushes_it_to_a_connected_wordpress_site(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);

        Http::fake(['example-shop.com/*' => Http::response(['ok' => true, 'product_id' => 5], 200)]);

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Shoes', 'slug' => 'shoes']);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'name' => 'Running Shoe',
            'slug' => 'running-shoe',
            'is_active' => true,
        ]);
        ProductVariant::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => 'RS-1',
            'selling_price' => 1200,
        ]);

        // Product::create() and ProductVariant::create() are two separate
        // saves, so this fires two separate pushes (product-with-no-
        // variants-yet, then product-with-the-variant) — expected
        // eventual-consistency behavior of an observer-per-save design.
        // Only the second one carries the variant, hence the ?? guards.
        Http::assertSent(function ($request) use ($product) {
            return $request->url() === 'https://example-shop.com/wp-json/metasoft/v1/products'
                && $request->method() === 'POST'
                && $request['id'] === $product->id
                && $request['name'] === 'Running Shoe'
                && ($request['variants'][0]['sku'] ?? null) === 'RS-1';
        });
    }

    public function test_saving_a_product_does_not_call_http_when_tenant_has_no_wordpress_connection(): void
    {
        $tenant = $this->makeTenant();

        Http::fake();

        Product::create(['tenant_id' => $tenant->id, 'name' => 'No Connection', 'slug' => 'no-connection']);

        Http::assertNothingSent();
    }

    public function test_deleting_a_product_sends_a_delete_request(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'To Delete', 'slug' => 'to-delete']);

        Http::fake(['example-shop.com/*' => Http::response(['ok' => true], 200)]);

        $product->delete();

        Http::assertSent(fn ($request) => $request->url() === "https://example-shop.com/wp-json/metasoft/v1/products/{$product->id}"
            && $request->method() === 'DELETE');
    }

    public function test_saving_a_variant_resyncs_the_parent_product(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt', 'slug' => 'shirt']);

        Http::fake(['example-shop.com/*' => Http::response(['ok' => true], 200)]);

        ProductVariant::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => 'SHIRT-1',
            'selling_price' => 500,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://example-shop.com/wp-json/metasoft/v1/products'
            && $request['id'] === $product->id);
    }

    // --- category push ---------------------------------------------------------

    public function test_saving_a_category_pushes_it_to_wordpress(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);

        Http::fake(['example-shop.com/*' => Http::response(['ok' => true], 200)]);

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Bags', 'slug' => 'bags']);

        Http::assertSent(fn ($request) => $request->url() === 'https://example-shop.com/wp-json/metasoft/v1/categories'
            && $request->method() === 'POST'
            && $request['id'] === $category->id
            && $request['name'] === 'Bags');
    }

    public function test_deleting_a_category_sends_a_delete_request(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);

        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Bags', 'slug' => 'bags']);

        Http::fake(['example-shop.com/*' => Http::response(['ok' => true], 200)]);

        $category->delete();

        Http::assertSent(fn ($request) => $request->url() === "https://example-shop.com/wp-json/metasoft/v1/categories/{$category->id}"
            && $request->method() === 'DELETE');
    }

    // --- stock push --------------------------------------------------------------

    public function test_an_inventory_update_pushes_the_new_total_stock(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);
        $warehouse = $this->makeWarehouse($tenant);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Mug', 'slug' => 'mug']);
        $variant = ProductVariant::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'sku' => 'MUG-1',
            'selling_price' => 300,
        ]);

        Http::fake(['example-shop.com/*' => Http::response(['ok' => true], 200)]);

        Inventory::create([
            'tenant_id' => $tenant->id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 42,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://example-shop.com/wp-json/metasoft/v1/stock'
            && $request['sku'] === 'MUG-1'
            && $request['quantity'] === 42);
    }

    // --- auth failure handling ----------------------------------------------------

    public function test_a_401_from_the_plugin_marks_the_connection_needs_reconnect(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $connection = $this->connectTenant($tenant, $user);

        Http::fake(['example-shop.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

        Product::create(['tenant_id' => $tenant->id, 'name' => 'Bad Auth', 'slug' => 'bad-auth']);

        $this->assertSame('needs_reconnect', $connection->fresh()->status);
    }

    // --- plan downgrade fail-closed -------------------------------------------------

    public function test_sync_stops_once_the_tenant_plan_loses_the_wordpress_connect_feature(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->connectTenant($tenant, $user);

        $tenant->plan->update(['features' => []]);

        Http::fake();

        Product::create(['tenant_id' => $tenant->id, 'name' => 'Downgraded', 'slug' => 'downgraded']);

        Http::assertNothingSent();
    }

    // --- tenant isolation --------------------------------------------------------

    public function test_tenant_bs_product_change_never_calls_tenant_as_wordpress_site(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->connectTenant($tenantA, $userA);

        $tenantB = $this->makeTenant();

        Http::fake();

        Product::create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Product', 'slug' => 'tenant-b-product']);

        Http::assertNothingSent();
    }
}
