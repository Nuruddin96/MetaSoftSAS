<?php

namespace App\Services\WordPress;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\WordPress\Concerns\PushesToWordPress;

/**
 * Phase 4 of the WordPress integration plan: pushes products, categories
 * and stock FROM MetaSoftSAS (the source of truth) TO a connected
 * WordPress/WooCommerce site. MetaSoftSAS never pulls product data back —
 * see class-woocommerce-sync.php on the plugin side for the receiving end
 * of these calls, and the Sync*ToWordPress jobs for what dispatches this
 * service outside the request/response cycle.
 *
 * Deliberately does not read Product/Category/ProductVariant business
 * rules itself (pricing, stock totals, slugs, etc.) — it only translates
 * already-computed model state into the wire payload the plugin expects,
 * same "no duplicated business logic" posture WordPressConnectorService
 * already established for the connection itself.
 *
 * connectionFor()/send() live in the PushesToWordPress trait, shared with
 * WordPressOrderSyncService (Phase 5) — see that trait's docblock.
 */
class WordPressProductSyncService
{
    use PushesToWordPress;

    public function pushProduct(Product $product): void
    {
        $connection = $this->connectionFor($product->tenant_id);

        if (! $connection) {
            return;
        }

        $this->send($connection, 'post', 'products', $this->productPayload($product));
    }

    public function deleteProduct(int $tenantId, int $productId): void
    {
        $connection = $this->connectionFor($tenantId);

        if (! $connection) {
            return;
        }

        $this->send($connection, 'delete', "products/{$productId}");
    }

    public function pushCategory(Category $category): void
    {
        $connection = $this->connectionFor($category->tenant_id);

        if (! $connection) {
            return;
        }

        $this->send($connection, 'post', 'categories', $this->categoryPayload($category));
    }

    public function deleteCategory(int $tenantId, int $categoryId): void
    {
        $connection = $this->connectionFor($tenantId);

        if (! $connection) {
            return;
        }

        $this->send($connection, 'delete', "categories/{$categoryId}");
    }

    /**
     * Lightweight compared to pushProduct() — an inventory adjustment
     * (POS sale, restock, manual correction) is by far the highest-
     * frequency event this integration will push, so it gets its own
     * small payload instead of re-sending the whole product/variant graph
     * on every stock change.
     */
    public function pushStock(ProductVariant $variant): void
    {
        $connection = $this->connectionFor($variant->tenant_id);

        if (! $connection) {
            return;
        }

        $this->send($connection, 'post', 'stock', [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'quantity' => $variant->totalStock(),
        ]);
    }

    protected function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'is_active' => (bool) $product->is_active,
            'is_featured' => (bool) $product->is_featured,
            'thumbnail_url' => $product->thumbnail_path ? asset('storage/'.$product->thumbnail_path) : null,
            'category_id' => $product->category_id,
            'images' => $product->images->sortBy('sort_order')->values()
                ->map(fn ($image) => asset('storage/'.$image->image_path))->all(),
            'variants' => $product->variants->values()->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'variant_name' => $variant->variant_name,
                'attributes' => $variant->attributes ?? [],
                'purchase_price' => (float) $variant->purchase_price,
                'selling_price' => (float) $variant->selling_price,
                'compare_at_price' => $variant->compare_at_price !== null ? (float) $variant->compare_at_price : null,
                'stock' => $variant->totalStock(),
                'is_active' => (bool) $variant->is_active,
            ])->all(),
        ];
    }

    protected function categoryPayload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'image_url' => $category->image_path ? asset('storage/'.$category->image_path) : null,
            'is_active' => (bool) $category->is_active,
        ];
    }
}
