<?php

namespace Tests\Feature\Tenant;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Product/variant creation via the tenant panel — stock-on-create bug fix,
 * the new attributes (Size/Color) write path, and the new compare_at_price
 * (offer price) write path. Tenant\ProductController::store()/update() are
 * the actual code under test, not a synthetic shape.
 */
class ProductVariantTest extends TestCase
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

    protected function makeWarehouse(Tenant $tenant): Warehouse
    {
        app()->instance('currentTenant', $tenant);

        return Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);
    }

    // --- 1. Stock on initial creation (already worked — regression guard) ---

    public function test_the_first_variants_stock_is_saved_on_product_creation(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Test Shirt',
            'variants' => [
                ['variant_name' => 'Default', 'selling_price' => 500, 'stock' => 20],
            ],
        ]);

        $response->assertRedirect();
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(20, $variant->totalStock());
    }

    // --- 2. The actual bug: additional variant added during edit ---

    public function test_a_second_variant_added_later_via_edit_gets_its_stock_saved(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Test Shirt', 'is_active' => 1]);
        $variantA = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'M', 'selling_price' => 500]);
        Inventory::create(['tenant_id' => $tenant->id, 'variant_id' => $variantA->id, 'warehouse_id' => Warehouse::first()->id, 'quantity' => 10]);

        // Editing the product and adding a brand-new second variant row (no id) with its own starting stock.
        $response = $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, 'products/'.$product->id), [
            'name' => 'Test Shirt',
            'variants' => [
                ['id' => $variantA->id, 'variant_name' => 'M', 'selling_price' => 500],
                ['variant_name' => 'L', 'selling_price' => 550, 'stock' => 15],
            ],
        ]);

        $response->assertRedirect();
        $variantB = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('variant_name', 'L')->first();
        $this->assertNotNull($variantB, 'the second variant must have been created');
        $this->assertNotNull(Inventory::where('variant_id', $variantB->id)->first(), 'an Inventory row must exist for a variant created during edit — this was the actual bug');
        $this->assertSame(15, $variantB->totalStock());
    }

    public function test_a_third_variant_added_in_the_same_edit_also_gets_correct_stock(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Test Shirt', 'is_active' => 1]);

        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, 'products/'.$product->id), [
            'name' => 'Test Shirt',
            'variants' => [
                ['variant_name' => 'M', 'selling_price' => 500, 'stock' => 5],
                ['variant_name' => 'L', 'selling_price' => 550, 'stock' => 7],
                ['variant_name' => 'XL', 'selling_price' => 600, 'stock' => 9],
            ],
        ]);

        $variants = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('product_id', $product->id)->get()->keyBy('variant_name');
        $this->assertSame(5, $variants['M']->totalStock());
        $this->assertSame(7, $variants['L']->totalStock());
        $this->assertSame(9, $variants['XL']->totalStock());
    }

    // --- 3. Offer/compare price ---

    public function test_compare_at_price_is_saved_when_greater_than_selling_price(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Discounted Item',
            'variants' => [
                ['variant_name' => 'Default', 'selling_price' => 100, 'compare_at_price' => 200, 'stock' => 5],
            ],
        ]);

        $response->assertRedirect();
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertEquals(200.0, (float) $variant->compare_at_price);
        $this->assertSame(50, $variant->discountPercent());
    }

    public function test_a_compare_at_price_lower_than_selling_price_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Bad Discount',
            'variants' => [
                ['variant_name' => 'Default', 'selling_price' => 200, 'compare_at_price' => 100, 'stock' => 5],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(), 'nothing should be saved when the offer price is invalid');
    }

    // --- 4. Attributes (Size/Color) ---

    public function test_two_option_attributes_are_saved_as_json(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Premium Shirt',
            'variants' => [
                ['variant_name' => 'Blue M', 'selling_price' => 500, 'stock' => 5, 'attr1_name' => 'Size', 'attr1_value' => 'M', 'attr2_name' => 'Color', 'attr2_value' => 'Blue'],
            ],
        ]);

        $response->assertRedirect();
        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(['Size' => 'M', 'Color' => 'Blue'], $variant->attributes);
    }

    public function test_no_attributes_given_leaves_attributes_null_not_an_empty_array(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Simple Product',
            'variants' => [
                ['variant_name' => 'Default', 'selling_price' => 500, 'stock' => 5],
            ],
        ]);

        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNull($variant->attributes);
    }

    // --- 5. Tenant isolation ---

    public function test_editing_a_product_never_creates_a_variant_for_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $this->makeWarehouse($tenantA);
        app()->instance('currentTenant', $tenantA);
        $product = Product::create(['tenant_id' => $tenantA->id, 'name' => 'A Product', 'is_active' => 1]);

        $this->actingAs($userA, 'tenant')->put($this->panelUrl($tenantA, 'products/'.$product->id), [
            'name' => 'A Product',
            'variants' => [
                ['variant_name' => 'New', 'selling_price' => 500, 'stock' => 10],
            ],
        ]);

        $newVariant = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->where('variant_name', 'New')->first();
        $this->assertSame($tenantA->id, $newVariant->tenant_id);
        $this->assertNotEquals($tenantB->id, $newVariant->tenant_id);
    }

    // --- 6a. Manual SKU (Variant Generator project — web form never had this field before) ---

    public function test_a_manually_supplied_sku_is_saved_on_create(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Shirt',
            'variants' => [
                ['variant_name' => 'Default', 'selling_price' => 500, 'sku' => 'MY-CUSTOM-SKU'],
            ],
        ]);

        $variant = ProductVariant::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame('MY-CUSTOM-SKU', $variant->sku);
    }

    public function test_a_blank_sku_on_update_never_clears_an_existing_variants_sku(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt', 'is_active' => 1]);
        $variant = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 500, 'sku' => 'ORIGINAL-SKU',
        ]);

        $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, 'products/'.$product->id), [
            'name' => 'Shirt',
            'variants' => [
                ['id' => $variant->id, 'variant_name' => 'Default', 'selling_price' => 550, 'sku' => ''],
            ],
        ]);

        $this->assertSame('ORIGINAL-SKU', $variant->fresh()->sku);
        $this->assertEquals(550.0, (float) $variant->fresh()->selling_price);
    }

    // --- 6. Duplicate combination protection (Variant Generator project) ---

    public function test_store_rejects_two_rows_with_the_identical_attribute_combination(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'products'), [
            'name' => 'Shirt',
            'variants' => [
                ['variant_name' => 'A', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S']],
                ['variant_name' => 'B', 'selling_price' => 420, 'attributes' => ['Size' => 'S', 'Color' => 'Black']],
            ],
        ]);

        $response->assertSessionHasErrors('variants');
        $this->assertSame(0, Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Shirt')->count());
    }

    public function test_update_rejects_two_rows_with_the_identical_attribute_combination(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $this->makeWarehouse($tenant);
        app()->instance('currentTenant', $tenant);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt', 'is_active' => 1]);
        $existing = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Black S', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S'],
        ]);

        $response = $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, 'products/'.$product->id), [
            'name' => 'Shirt',
            'variants' => [
                ['id' => $existing->id, 'variant_name' => 'Black S', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S']],
                ['variant_name' => 'Also Black S', 'selling_price' => 420, 'attributes' => ['Size' => 'S', 'Color' => 'Black']],
            ],
        ]);

        $response->assertSessionHasErrors('variants');
        $this->assertSame(1, ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->count());
    }
}
