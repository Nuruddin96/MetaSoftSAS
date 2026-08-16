<?php

namespace Tests\Feature;

use App\Models\ProductImage;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * Regression coverage for a real production discrepancy: schema.sql's
 * product_images definition was missing created_at/updated_at, even though
 * the live production table (and App\Models\ProductImage's
 * $timestamps = true) already has both — confirmed directly against
 * production before this fix, not assumed. Both call shapes below mirror
 * Tenant\ProductController's actual code exactly (relation create() on
 * product store/update at lines 72/160, bulk update() on the gallery
 * reorder endpoint at line 229) — not a synthetic shape.
 */
class ProductImageTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
    }

    public function test_creating_a_gallery_image_via_the_product_relation_sets_timestamps(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);
        app()->instance('currentTenant', $tenant);

        $image = $variant->product->images()->create(['image_path' => 'products/gallery/one.jpg', 'sort_order' => 0]);

        $this->assertNotNull($image->created_at);
        $this->assertNotNull($image->updated_at);
        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'tenant_id' => $tenant->id,
            'image_path' => 'products/gallery/one.jpg',
        ]);
    }

    public function test_multiple_gallery_images_can_be_created_in_sequence_like_a_real_upload(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);
        app()->instance('currentTenant', $tenant);

        foreach (['a.jpg', 'b.jpg', 'c.jpg'] as $i => $path) {
            $variant->product->images()->create(['image_path' => "products/gallery/$path", 'sort_order' => $i]);
        }

        $this->assertSame(3, $variant->product->images()->count());
    }

    /** Mirrors Tenant\ProductController::reorderImages()'s exact bulk-update shape. */
    public function test_reordering_gallery_images_via_bulk_update_sets_updated_at(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);
        app()->instance('currentTenant', $tenant);
        $image = $variant->product->images()->create(['image_path' => 'products/gallery/one.jpg', 'sort_order' => 0]);
        $originalUpdatedAt = $image->updated_at;

        $this->travel(1)->minute();
        ProductImage::where('id', $image->id)->update(['sort_order' => 5]);

        $image->refresh();
        $this->assertSame(5, $image->sort_order);
        $this->assertTrue($image->updated_at->gt($originalUpdatedAt));
    }

    public function test_a_gallery_image_can_be_deleted(): void
    {
        $tenant = $this->makeTenant();
        $variant = $this->makeSellableVariant($tenant->id);
        app()->instance('currentTenant', $tenant);
        $image = $variant->product->images()->create(['image_path' => 'products/gallery/one.jpg', 'sort_order' => 0]);

        $image->delete();

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    /** Tenant isolation: reordering must never touch another tenant's row, same withGlobalScope guarantee every other tenant-owned model already relies on. */
    public function test_reordering_never_affects_another_tenants_image(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $variantA = $this->makeSellableVariant($tenantA->id);
        app()->instance('currentTenant', $tenantA);
        $imageA = $variantA->product->images()->create(['image_path' => 'products/gallery/a.jpg', 'sort_order' => 0]);

        $variantB = $this->makeSellableVariant($tenantB->id);
        app()->instance('currentTenant', $tenantB);

        // Tenant B's own scoped query can never see, let alone update, tenant A's row.
        ProductImage::where('id', $imageA->id)->update(['sort_order' => 9]);

        $this->assertDatabaseHas('product_images', ['id' => $imageA->id, 'sort_order' => 0]);
    }
}
