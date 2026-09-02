<?php

namespace App\Services\WordPress;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WordPressConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 */
class WordPressProductSyncService
{
    /**
     * Null when the tenant has no live, connected WordPress site to push
     * to — every public method below is a silent no-op in that case
     * (checked first, before building any payload) so call sites never
     * need their own "is this tenant even connected" guard.
     *
     * Also fails closed on a plan that has since lost the wordpress_connect
     * feature (a downgrade after connecting), matching EnsureFeatureEnabled's
     * fail-closed posture for the connect action itself — an existing
     * connection stays intact (see WordPressConnectController's docblock on
     * why disconnect is never gated), but this phase's background sync
     * traffic stops until the plan is upgraded again or the tenant
     * reconnects.
     */
    public function connectionFor(int $tenantId): ?WordPressConnection
    {
        $connection = WordPressConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'connected')
            ->first();

        if (! $connection || ! $connection->wp_rest_url) {
            return null;
        }

        if (! $connection->tenant?->plan?->hasFeature('wordpress_connect')) {
            return null;
        }

        return $connection;
    }

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

    /**
     * Every outbound call funnels through here so the bearer-secret
     * auth, timeout, and failure handling (log + flip needs_reconnect on
     * an auth rejection) live in exactly one place — same "single choke
     * point" shape WordPressConnectorService::verify()/disconnect() use
     * for their own outbound calls.
     */
    protected function send(WordPressConnection $connection, string $method, string $path, array $payload = []): ?Response
    {
        $url = rtrim($connection->wp_rest_url, '/').'/metasoft/v1/'.$path;

        try {
            /** @var PendingRequest $request */
            $request = Http::timeout(15)->withToken($connection->outbound_secret);
            $response = $payload ? $request->{$method}($url, $payload) : $request->{$method}($url);
        } catch (ConnectionException $e) {
            Log::warning('WordPress sync: connection failure.', [
                'tenant_id' => $connection->tenant_id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->unauthorized() || $response->forbidden()) {
            // Same posture as FacebookOAuthService::handleGraphFailure()'s
            // isInvalidToken() branch — the outbound_secret the plugin
            // holds no longer matches (site reinstalled, credentials
            // cleared locally, etc.), so mark the connection for a
            // required reconnect rather than silently retrying forever.
            $connection->update(['status' => 'needs_reconnect']);

            Log::warning('WordPress sync: outbound secret rejected by plugin — marked needs_reconnect.', [
                'tenant_id' => $connection->tenant_id,
                'path' => $path,
            ]);

            return $response;
        }

        if ($response->failed()) {
            Log::warning('WordPress sync: push failed.', [
                'tenant_id' => $connection->tenant_id,
                'path' => $path,
                'status' => $response->status(),
            ]);
        }

        return $response;
    }
}
