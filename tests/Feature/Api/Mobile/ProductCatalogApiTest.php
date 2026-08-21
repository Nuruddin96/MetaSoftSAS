<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers ProductCatalogController — the real Products feature endpoint
 * introduced to fix the confirmed contract mismatch where the mobile
 * Flutter Product.fromJson() (expects id/name/category_name/variants[]/
 * warehouse_stock[]) was being pointed at ProductController::index()'s
 * variant-search response (variant_id/product_name/... — no `id` key at
 * all), which would throw a null-cast exception on every real row.
 */
class ProductCatalogApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        // InteractsWithCommerceSchema's hand-built `plans` table predates
        // `max_products` (schema.sql has it; Tenant::isWithinLimit() reads
        // it) — added here rather than editing that shared trait, matching
        // InteractsWithApiSchema::setUpApiSchema()'s own precedent of
        // scoping additive-column patches to the suite that needs them.
        if (! Schema::hasColumn('plans', 'max_products')) {
            Schema::table('plans', fn (Blueprint $table) => $table->integer('max_products')->nullable());
        }
    }

    protected function makeProductWithStock(int $tenantId, array $productAttrs = [], array $variantAttrs = [], int $stock = 10): Product
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));

        $product = Product::create(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'Test Product',
            'is_active' => 1,
        ], $productAttrs));

        $variant = ProductVariant::create(array_merge([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'variant_name' => 'Default',
            'selling_price' => 250,
            'purchase_price' => 150,
        ], $variantAttrs));

        $warehouse = Warehouse::create(['tenant_id' => $tenantId, 'name' => 'Main', 'is_default' => 1]);

        Inventory::create([
            'tenant_id' => $tenantId,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $stock,
        ]);

        return $product->fresh();
    }

    public function test_list_response_shape_matches_the_flutter_product_contract(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeProductWithStock($tenant->id, ['name' => 'Contract Test Product']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/product-catalog')->assertOk();

        // The exact keys Product.fromJson requires — id (int, previously
        // absent — the response only had variant_id), name, category_name,
        // selling_price, stock_quantity. Presence of `id` here is the
        // regression check for the original crash.
        $response->assertJsonStructure([
            'data' => [['id', 'name', 'sku', 'category_id', 'category_name', 'selling_price', 'stock_quantity', 'is_active', 'has_variants', 'thumbnail_url']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

        $row = $response->json('data.0');
        $this->assertIsInt($row['id']);
        $this->assertArrayNotHasKey('variant_id', $row);
        $this->assertSame('Contract Test Product', $row['name']);
        $this->assertEquals(250.0, $row['selling_price']);
        $this->assertSame(10, $row['stock_quantity']);
    }

    public function test_list_supports_search_category_and_low_stock_filters(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Snacks']);
        app()->forgetInstance('currentTenant');

        $this->makeProductWithStock($tenant->id, ['name' => 'Findable Chips', 'category_id' => $category->id], [], 10);
        $this->makeProductWithStock($tenant->id, ['name' => 'Other Item'], ['low_stock_threshold' => 5], 2);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $bySearch = $this->getJson('/api/mobile/v1/product-catalog?q=Findable')->assertOk();
        $this->assertCount(1, $bySearch->json('data'));
        $this->assertSame('Findable Chips', $bySearch->json('data.0.name'));

        $byCategory = $this->getJson("/api/mobile/v1/product-catalog?category_id={$category->id}")->assertOk();
        $this->assertCount(1, $byCategory->json('data'));

        $byLowStock = $this->getJson('/api/mobile/v1/product-catalog?low_stock=1')->assertOk();
        $names = collect($byLowStock->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Other Item'));
        $this->assertFalse($names->contains('Findable Chips'));
    }

    public function test_show_returns_variants_warehouse_stock_and_images(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeProductWithStock($tenant->id, [], [], 7);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/mobile/v1/product-catalog/{$product->id}")->assertOk();

        $response->assertJsonStructure([
            'id', 'name', 'selling_price', 'stock_quantity', 'variants' => [['id', 'name', 'sku', 'selling_price', 'stock_quantity', 'is_active']],
            'warehouse_stock' => [['warehouse_name', 'quantity']],
            'images',
        ]);
        $this->assertSame(7, $response->json('warehouse_stock.0.quantity'));
        $this->assertSame('Main', $response->json('warehouse_stock.0.warehouse_name'));
    }

    public function test_create_product_with_single_default_variant(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'New Product',
            'selling_price' => 199,
            'initial_stock' => 15,
            'sku' => 'MY-SKU-1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'New Product')
            ->assertJsonPath('selling_price', 199)
            ->assertJsonPath('stock_quantity', 15)
            ->assertJsonPath('sku', 'MY-SKU-1')
            ->assertJsonCount(1, 'variants');
    }

    public function test_create_product_with_multiple_variant_names(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Variant Product',
            'selling_price' => 300,
            'variant_names' => ['Small', 'Large'],
        ]);

        $response->assertCreated()->assertJsonCount(2, 'variants')->assertJsonPath('has_variants', true);
        $this->assertSame(['Small', 'Large'], collect($response->json('variants'))->pluck('name')->all());
    }

    public function test_create_product_rejects_missing_name_and_price(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/product-catalog', ['selling_price' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/mobile/v1/product-catalog', ['name' => 'No Price'])
            ->assertStatus(422)->assertJsonValidationErrors('selling_price');
    }

    public function test_create_product_rejects_category_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        app()->instance('currentTenant', $tenantB);
        $foreignCategory = Category::create(['tenant_id' => $tenantB->id, 'name' => 'Foreign']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userA);

        $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Cross Tenant Category',
            'selling_price' => 100,
            'category_id' => $foreignCategory->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    }

    public function test_create_product_respects_plan_product_limit(): void
    {
        $plan = $this->makePlan(['max_products' => 1]);
        $tenant = $this->makeTenant(['plan_id' => $plan->id]);
        $user = $this->makeUser($tenant->id);
        $this->makeProductWithStock($tenant->id);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Over Limit Product',
            'selling_price' => 100,
        ])->assertStatus(422);
    }

    public function test_update_single_variant_product_updates_price_and_sku(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $product = $this->makeProductWithStock($tenant->id, ['name' => 'Old Name']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}", [
            'name' => 'New Name',
            'selling_price' => 450,
            'sku' => 'UPDATED-SKU',
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('selling_price', 450)
            ->assertJsonPath('sku', 'UPDATED-SKU')
            ->assertJsonPath('is_active', false);
    }

    public function test_update_multi_variant_product_never_overwrites_variant_prices(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Multi', 'is_active' => 1, 'has_variants' => 1]);
        $v1 = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Small', 'selling_price' => 100]);
        $v2 = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Large', 'selling_price' => 150]);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($user);

        $this->postJson("/api/mobile/v1/product-catalog/{$product->id}", [
            'name' => 'Multi Renamed',
            'selling_price' => 999,
        ])->assertOk();

        $this->assertSame(100.0, (float) $v1->fresh()->selling_price);
        $this->assertSame(150.0, (float) $v2->fresh()->selling_price);
    }

    public function test_tenant_cannot_view_or_update_another_tenants_product(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $productA = $this->makeProductWithStock($tenantA->id);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userB);

        $this->getJson("/api/mobile/v1/product-catalog/{$productA->id}")->assertNotFound();
        $this->postJson("/api/mobile/v1/product-catalog/{$productA->id}", ['name' => 'Hijack', 'selling_price' => 1])
            ->assertNotFound();
    }

    public function test_tenant_product_list_never_includes_other_tenants_products(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);

        $this->makeProductWithStock($tenantA->id, ['name' => 'Mine']);
        $this->makeProductWithStock($tenantB->id, ['name' => 'Not Mine']);
        app()->forgetInstance('currentTenant');

        Sanctum::actingAs($userA);

        $names = collect($this->getJson('/api/mobile/v1/product-catalog')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Not Mine'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/product-catalog')->assertUnauthorized();
    }
}
