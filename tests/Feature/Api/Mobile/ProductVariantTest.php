<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** Web/Flutter parity project — description was read-only on mobile before (presentDetail() returned it, no write path ever set it). */
    public function test_store_and_update_save_description(): void
    {
        $tenant = $this->actingAsTenantUser();

        $created = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Shirt', 'selling_price' => 400, 'description' => 'A nice shirt.',
        ])->assertCreated();
        $this->assertSame('A nice shirt.', $created->json('description'));

        $product = Product::find($created->json('id'));
        $this->postJson("/api/mobile/v1/product-catalog/{$product->id}", [
            'name' => 'Shirt', 'description' => 'An even nicer shirt.',
        ])->assertOk()->assertJsonPath('description', 'An even nicer shirt.');
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

    /** Web/Flutter parity project — compare_at_price and per-row stock were mobile-only readable before, now writable via variants[] rows too. */
    public function test_store_saves_per_row_compare_at_price_and_stock(): void
    {
        $tenant = $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Shirt',
            'variants' => [
                ['name' => 'S', 'selling_price' => 400, 'compare_at_price' => 500, 'stock' => 12, 'attributes' => ['Size' => 'S']],
            ],
        ]);

        $response->assertCreated();
        $variant = ProductVariant::where('product_id', $response->json('id'))->first();
        $this->assertEquals(500.0, (float) $variant->compare_at_price);
        $this->assertSame(12, $variant->totalStock());
    }

    public function test_store_variant_and_update_variant_save_compare_at_price(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'M', 'selling_price' => 400]);

        $addResponse = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/variants", [
            'name' => 'L', 'selling_price' => 420, 'compare_at_price' => 500,
        ])->assertCreated();
        $newVariant = ProductVariant::find($addResponse->json('id'));
        $this->assertEquals(500.0, (float) $newVariant->compare_at_price);

        $this->patchJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$newVariant->id}", ['compare_at_price' => 480])
            ->assertOk();
        $this->assertEquals(480.0, (float) $newVariant->fresh()->compare_at_price);
    }

    /** Variant Generator project — two rows with the identical combination (any key order) must be rejected. */
    public function test_store_rejects_two_variants_with_the_identical_attribute_combination(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Shirt',
            'variants' => [
                ['name' => 'A', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S']],
                ['name' => 'B', 'selling_price' => 420, 'attributes' => ['Size' => 'S', 'Color' => 'Black']],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('variants');
        $this->assertSame(0, Product::where('name', 'Shirt')->count());
    }

    public function test_store_variant_rejects_a_combination_that_already_exists_on_the_product(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Black S', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S'],
        ]);

        $response = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/variants", [
            'selling_price' => 420, 'attributes' => ['Size' => 'S', 'Color' => 'Black'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('attributes');
        $this->assertSame(1, ProductVariant::where('product_id', $product->id)->count());
    }

    public function test_update_variant_rejects_changing_into_a_combination_another_sibling_already_has(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Black S', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S'],
        ]);
        $white = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'White S', 'selling_price' => 400, 'attributes' => ['Color' => 'White', 'Size' => 'S'],
        ]);

        $response = $this->patchJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$white->id}", [
            'attributes' => ['Color' => 'Black', 'Size' => 'S'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('attributes');
        $this->assertSame(['Color' => 'White', 'Size' => 'S'], $white->fresh()->attributes);
    }

    /** Editing a variant's OWN combination back to itself (e.g. only changing price) must never trip the duplicate guard. */
    public function test_update_variant_allows_keeping_its_own_unchanged_combination(): void
    {
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        $variant = ProductVariant::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'variant_name' => 'Black S', 'selling_price' => 400, 'attributes' => ['Color' => 'Black', 'Size' => 'S'],
        ]);

        $this->patchJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$variant->id}", [
            'selling_price' => 450, 'attributes' => ['Color' => 'Black', 'Size' => 'S'],
        ])->assertOk();

        $this->assertEquals(450.0, (float) $variant->fresh()->selling_price);
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

    /** Web/Flutter parity project — gallery images were read-only on mobile before (thumbnail was the only writable image). */
    public function test_gallery_images_can_be_uploaded_and_deleted(): void
    {
        Storage::fake('public');
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 400]);

        $response = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ])->assertCreated();

        $this->assertSame(2, count($response->json('images')));
        $imageId = $response->json('images.0.id');

        $this->deleteJson("/api/mobile/v1/product-catalog/{$product->id}/images/{$imageId}")->assertOk();
        $this->assertSame(1, $product->images()->count());
    }

    public function test_gallery_upload_is_capped_at_eight_images_total(): void
    {
        Storage::fake('public');
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 400]);

        $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images", [
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("img{$i}.jpg"), range(1, 8)),
        ])->assertCreated();

        $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('one-too-many.jpg')],
        ])->assertStatus(422);

        $this->assertSame(8, $product->images()->count());
    }

    public function test_gallery_image_delete_rejects_another_tenants_product(): void
    {
        Storage::fake('public');
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreignProduct = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);
        $foreignImage = $foreignProduct->images()->create(['tenant_id' => $otherTenant->id, 'image_path' => 'products/gallery/x.jpg', 'sort_order' => 0]);

        $this->deleteJson("/api/mobile/v1/product-catalog/{$foreignProduct->id}/images/{$foreignImage->id}")->assertNotFound();
    }

    /** Web/Flutter parity project — purchase_price ("কেনা দাম") was write-only on web before; mobile never saved or returned it. */
    public function test_store_and_variant_endpoints_save_purchase_price(): void
    {
        $tenant = $this->actingAsTenantUser();

        $created = $this->postJson('/api/mobile/v1/product-catalog', [
            'name' => 'Shirt',
            'variants' => [['selling_price' => 500, 'purchase_price' => 300, 'attributes' => ['Color' => 'Black']]],
        ])->assertCreated();
        $this->assertEquals(300.0, $created->json('variants.0.purchase_price'));

        $product = Product::find($created->json('id'));
        $variantId = $created->json('variants.0.id');

        $added = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/variants", [
            'name' => 'White', 'selling_price' => 600, 'purchase_price' => 350, 'attributes' => ['Color' => 'White'],
        ])->assertCreated();

        $this->patchJson("/api/mobile/v1/product-catalog/{$product->id}/variants/{$variantId}", [
            'purchase_price' => 320,
        ])->assertOk();

        $show = $this->getJson("/api/mobile/v1/product-catalog/{$product->id}")->assertOk();
        $variants = collect($show->json('variants'))->keyBy('id');
        $this->assertEquals(320.0, $variants[$variantId]['purchase_price']);
        $this->assertEquals(350.0, $variants[$added->json('id')]['purchase_price']);
    }

    /** Web/Flutter parity project — mirrors Tenant\ProductController::reorderImages(); mobile had no reorder endpoint before this. */
    public function test_gallery_images_can_be_reordered(): void
    {
        Storage::fake('public');
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 400]);

        $uploaded = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
        ])->assertCreated();
        $ids = collect($uploaded->json('images'))->pluck('id')->all();
        $reversed = array_reverse($ids);

        $response = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images/reorder", ['order' => $reversed])
            ->assertOk();

        $this->assertSame($reversed, collect($response->json('images'))->pluck('id')->all());
        $this->assertSame(0, (int) $product->images()->find($reversed[0])->sort_order);
        $this->assertSame(1, (int) $product->images()->find($reversed[1])->sort_order);
    }

    public function test_gallery_reorder_rejects_an_id_list_that_does_not_match_the_products_images(): void
    {
        Storage::fake('public');
        $tenant = $this->actingAsTenantUser();
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Shirt']);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'variant_name' => 'Default', 'selling_price' => 400]);

        $uploaded = $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images", [
            'images' => [UploadedFile::fake()->image('one.jpg')],
        ])->assertCreated();
        $realId = $uploaded->json('images.0.id');

        $this->postJson("/api/mobile/v1/product-catalog/{$product->id}/images/reorder", ['order' => [$realId, 999999]])
            ->assertStatus(422);
    }

    public function test_gallery_reorder_rejects_another_tenants_product(): void
    {
        Storage::fake('public');
        $this->actingAsTenantUser();
        $otherTenant = $this->makeTenant();
        $foreignProduct = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'Not Yours']);
        $foreignImage = $foreignProduct->images()->create(['tenant_id' => $otherTenant->id, 'image_path' => 'products/gallery/x.jpg', 'sort_order' => 0]);

        $this->postJson("/api/mobile/v1/product-catalog/{$foreignProduct->id}/images/reorder", ['order' => [$foreignImage->id]])
            ->assertNotFound();
    }
}
