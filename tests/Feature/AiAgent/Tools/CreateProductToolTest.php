<?php

namespace Tests\Feature\AiAgent\Tools;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\AI\Tools\CreateProductTool;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

class CreateProductToolTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    public function test_is_mutating(): void
    {
        $this->assertTrue((new CreateProductTool)->isMutating());
    }

    public function test_preview_rejects_a_blank_name(): void
    {
        $tenant = $this->makeTenant();

        $preview = (new CreateProductTool)->preview($tenant->id, ['name' => '', 'selling_price' => 100]);

        $this->assertArrayHasKey('error', $preview);
    }

    public function test_preview_rejects_a_missing_or_negative_price(): void
    {
        $tenant = $this->makeTenant();

        $this->assertArrayHasKey('error', (new CreateProductTool)->preview($tenant->id, ['name' => 'X']));
        $this->assertArrayHasKey('error', (new CreateProductTool)->preview($tenant->id, ['name' => 'X', 'selling_price' => -5]));
    }

    public function test_preview_resolves_an_existing_category_by_name(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Shirts']);

        $preview = (new CreateProductTool)->preview($tenant->id, ['name' => 'Blue Shirt', 'selling_price' => 500, 'category_name' => 'shirt']);

        $this->assertSame($category->id, $preview['resolved_args']['category_id']);
    }

    public function test_preview_never_resolves_another_tenants_category(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        app()->instance('currentTenant', $tenantB);
        Category::create(['tenant_id' => $tenantB->id, 'name' => 'Shirts']);

        $preview = (new CreateProductTool)->preview($tenantA->id, ['name' => 'X', 'selling_price' => 500, 'category_name' => 'Shirts']);

        $this->assertNull($preview['resolved_args']['category_id']);
    }

    public function test_handle_creates_the_product_variant_and_inventory(): void
    {
        $tenant = $this->makeTenant();
        app()->instance('currentTenant', $tenant);
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);
        $this->actingAs($this->makeUser($tenant->id), 'tenant');

        $preview = (new CreateProductTool)->preview($tenant->id, ['name' => 'Blue Shirt', 'selling_price' => 500, 'initial_stock' => 20]);
        $result = (new CreateProductTool)->handle($tenant->id, $preview['resolved_args']);

        $this->assertTrue($result['success']);

        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Blue Shirt')->first();
        $this->assertNotNull($product);

        $variant = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->first();
        $this->assertNotNull($variant);
        $this->assertSame(500.0, (float) $variant->selling_price);

        $this->assertSame(20, (int) Inventory::withoutGlobalScopes()->where('variant_id', $variant->id)->value('quantity'));
    }

    public function test_handle_always_scopes_the_created_product_to_the_tenant_id_parameter_never_to_anything_in_args(): void
    {
        // handle() trusts ONLY the $tenantId parameter — AiToolRegistry::call()
        // is what guarantees that parameter is the real, server-derived
        // tenant, never anything AI/client-supplied. This proves handle()
        // itself never reads a tenant_id-shaped key out of $args, even if
        // one were somehow present.
        $tenantA = $this->makeTenant();
        app()->instance('currentTenant', $tenantA);
        $this->actingAs($this->makeUser($tenantA->id), 'tenant');

        $result = (new CreateProductTool)->handle($tenantA->id, [
            'name' => 'Sneaky Product', 'selling_price' => 500, 'tenant_id' => 999999,
        ]);

        $this->assertTrue($result['success']);
        $product = Product::withoutGlobalScopes()->where('name', 'Sneaky Product')->first();
        $this->assertSame($tenantA->id, $product->tenant_id, 'must use the trusted parameter, not the tenant_id key stuffed into args');
    }
}
