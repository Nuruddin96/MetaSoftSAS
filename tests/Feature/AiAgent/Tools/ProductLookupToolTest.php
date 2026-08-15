<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\AI\Tools\ProductLookupTool;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class ProductLookupToolTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    protected function makeProductWithStock(int $tenantId, array $productAttrs = [], array $variantAttrs = [], int $stock = 10): ProductVariant
    {
        app()->instance('currentTenant', Tenant::find($tenantId));

        $product = Product::create(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'Test Product',
            'is_active' => 1,
        ], $productAttrs));

        $variant = ProductVariant::create(array_merge([
            'tenant_id' => $tenantId,
            'product_id' => $product->id,
            'variant_name' => 'Default',
            'selling_price' => 500,
            'purchase_price' => 300,
            'low_stock_threshold' => 5,
        ], $variantAttrs));

        $warehouse = Warehouse::create(['tenant_id' => $tenantId, 'name' => 'Main', 'is_default' => 1]);

        Inventory::create([
            'tenant_id' => $tenantId,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => $stock,
        ]);

        return $variant;
    }

    public function test_returns_only_the_given_tenants_products(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeProductWithStock($tenantA->id);
        $this->makeProductWithStock($tenantB->id);

        $result = (new ProductLookupTool)->handle($tenantA->id, []);

        $this->assertSame(1, $result['count']);
    }

    public function test_never_trusts_a_tenant_id_supplied_inside_args(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->makeProductWithStock($tenantB->id);

        $result = (new ProductLookupTool)->handle($tenantA->id, ['tenant_id' => $tenantB->id]);

        $this->assertSame(0, $result['count']);
    }

    public function test_filters_by_name_partial_match(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, ['name' => 'Red Cotton Shirt']);
        $this->makeProductWithStock($tenant->id, ['name' => 'Blue Jeans']);

        $result = (new ProductLookupTool)->handle($tenant->id, ['name' => 'shirt']);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Red Cotton Shirt', $result['products'][0]['name']);
    }

    public function test_excludes_inactive_products_by_default(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, ['name' => 'Active Product', 'is_active' => 1]);
        $this->makeProductWithStock($tenant->id, ['name' => 'Inactive Product', 'is_active' => 0]);

        $result = (new ProductLookupTool)->handle($tenant->id, []);

        $this->assertSame(1, $result['count']);
        $this->assertSame('Active Product', $result['products'][0]['name']);
    }

    public function test_active_only_false_includes_inactive_products(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, ['name' => 'Inactive Product', 'is_active' => 0]);

        $result = (new ProductLookupTool)->handle($tenant->id, ['active_only' => false]);

        $this->assertSame(1, $result['count']);
    }

    public function test_includes_variant_price_and_stock(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, [], ['selling_price' => 750], stock: 42);

        $result = (new ProductLookupTool)->handle($tenant->id, []);

        $variant = $result['products'][0]['variants'][0];
        $this->assertSame(750.0, $variant['selling_price']);
        $this->assertSame(42, $variant['stock_quantity']);
        $this->assertFalse($variant['is_low_stock']);
    }

    public function test_flags_low_stock_variants(): void
    {
        $tenant = $this->makeTenant();
        $this->makeProductWithStock($tenant->id, [], ['low_stock_threshold' => 10], stock: 3);

        $result = (new ProductLookupTool)->handle($tenant->id, []);

        $this->assertTrue($result['products'][0]['variants'][0]['is_low_stock']);
    }

    public function test_includes_category_name_when_present(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Shirts']);
        $this->makeProductWithStock($tenant->id, ['category_id' => $category->id]);

        $result = (new ProductLookupTool)->handle($tenant->id, []);

        $this->assertSame('Shirts', $result['products'][0]['category']);
    }
}
