<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Catalog Architecture project: covers (1) the new attribute-aware
 * `variants[]` create path alongside the ORIGINAL flat `variant_names[]` +
 * single `selling_price` path (both must keep working — see
 * ProductCatalogController::store()'s doc comment), (2) that an existing
 * variant's `attributes` JSON — whatever shape it already has — now comes
 * back in the API response without being altered, and (3) the new
 * single-variant add/edit/delete endpoints, including the "at least one
 * variant" guard.
 */
class ProductVariantTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();
    }

    private function actingAsTenantUser(): \App\Models\Tenant
    {
        $tenant = $this->makeTenant();
        Sanctum::actingAs($this->makeUser($tenant->id));
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);

        return $tenant;
    }

    public function test_store_still_creates_a_simple_single_variant_product_via_the_original_flat_shape(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/product-catalog', ['name' => 'Simple Product', 'selling_price' => 500]);

        $response->assertCreated();
        $this->assertSame(1, count($response->json('variants')));
        $this->assertEquals(500.0, $response->json('variants.0.selling_price'));
    }

    public function test_store_still_creates_multiple_variants_via_the_original_variant_names_shape(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'T-Shirt', 'selling_price' => 400, 'variant_names' => ['Small', 'Large'],
        ]);

        $response->assertCreated();
        $this->assertSame(['Small', 'Large'], collect($response->json('variants'))->pluck('name')->all());
    }

    public function test_store_creates_attribute_aware_variants_via_the_new_variants_array(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'iPhone 15',
            'variants' => [
                ['name' => 'Black 128GB', 'selling_price' => 90000, 'attributes' => ['Color' => 'Black', 'Storage' => '128GB']],
                ['name' => 'Blue 256GB', 'selling_price' => 105000, 'attributes' => ['Color' => 'Blue', 'Storage' => '256GB']],
            ],
        ]);

        $response->assertCreated();
        $variants = $response->json('variants');
        $this->assertCount(2, $variants);
        $this->assertSame(['Color' => 'Black', 'Storage' => '128GB'], $variants[0]['attributes']);
        $this->assertEquals(90000.0, $variants[0]['selling_price']);
    }

    /**
     * A variant created long before this project existed may have any JSON
     * shape (or none) in `attributes` — the API must return it exactly as
     * stored, never coerce or crash on an unexpected shape.
     */
    public function test_show_returns_an_existing_variants_attributes_unmodified(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Old Product']);
        ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 200,
            'attributes' => ['Size' => 'M', 'Color' => 'Blue'],
        ]);

        $response = $this->getJson("/api/mobile/v1/product-catalog/{$product->id}")->assertOk();

        $this->assertSame(['Size' => 'M', 'Color' => 'Blue'], $response->json('variants.0.attributes'));
    }

    /**
     * Catalog Architecture project — a product assigned to a subcategory
     * must expose BOTH the subcategory name (category_name, unchanged)
     * and its parent's name (parent_category_name, new), so the client
     * can show "Category: X / Subcategory: Y" instead of just Y.
     */
    public function test_show_includes_parent_category_name_when_the_products_category_is_a_subcategory(): void
    {
        $tenant = $this->actingAsTenantUser();
        $parent = \App\Models\Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        $sub = \App\Models\Category::create(['tenant_id' => $tenant->id, 'name' => 'Cleanser', 'parent_id' => $parent->id]);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Foam Wash', 'category_id' => $sub->id]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 200]);

        $response = $this->getJson("/api/mobile/v1/product-catalog/{$product->id}")->assertOk();

        $response->assertJsonPath('category_name', 'Cleanser')->assertJsonPath('parent_category_name', 'Face Care');
    }

    public function test_show_parent_category_name_is_null_for_a_top_level_category(): void
    {
        $tenant = $this->actingAsTenantUser();
        $category = \App\Models\Category::create(['tenant_id' => $tenant->id, 'name' => 'Face Care']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Foam Wash', 'category_id' => $category->id]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 200]);

        $response = $this->getJson("/api/mobile/v1/product-catalog/{$product->id}")->assertOk();

        $response->assertJsonPath('parent_category_name', null);
    }

    public function test_show_returns_null_attributes_for_a_variant_that_never_had_any(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Plain Product']);
        ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Default', 'selling_price' => 200,
        ]);

        $response = $this->getJson("/api/mobile/v1/product-catalog/{$product->id}")->assertOk();

        $this->assertNull($response->json('variants.0.attributes'));
    }

    public function test_store_variant_adds_a_new_variant_to_an_existing_product(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'M', 'selling_price' => 400]);

        $response = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/variants", [
            'name' => 'L', 'selling_price' => 420, 'attributes' => ['Size' => 'L'],
        ]);

        $response->assertCreated();
        $this->assertSame(2, ProductVariant::where('product_id', $product->id)->count());
    }

    public function test_update_variant_edits_its_own_fields_without_touching_siblings(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        $m = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'M', 'selling_price' => 400]);
        $l = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'L', 'selling_price' => 420]);

        $this->patchJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$m->id}", ['selling_price' => 450])->assertOk();

        $this->assertSame(450.0, (float) $m->fresh()->selling_price);
        $this->assertSame(420.0, (float) $l->fresh()->selling_price);
    }

    public function test_destroy_variant_removes_it_when_siblings_remain(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        $m = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'M', 'selling_price' => 400]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'L', 'selling_price' => 420]);

        $this->deleteJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$m->id}")->assertOk();

        $this->assertSame(1, ProductVariant::where('product_id', $product->id)->count());
    }

    /** "Every product has at least ONE variant row" — product_variants' own schema comment. */
    public function test_destroy_variant_refuses_to_remove_the_last_remaining_variant(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        $only = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 400]);

        $this->deleteJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$only->id}")->assertStatus(422);
        $this->assertSame(1, ProductVariant::where('product_id', $product->id)->count());
    }

    public function test_variant_endpoints_reject_another_tenants_product(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreignProduct = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);
        ProductVariant::create(['tenant_id' => $otherTenant->id, 'product_id' => $foreignProduct->id, 'variant_name' => 'Default', 'selling_price' => 100]);

        $this->postJson("/api/mobile/v1/product-catalog/{$foreignProduct->id}/variants", ['selling_price' => 100])->assertNotFound();
    }
}
