<?php

namespace App\Services\AI\Tools;

use App\Models\Product;

/**
 * Read-only product/stock lookup — reuses Product/ProductVariant exactly
 * as stored (see Tenant\ProductController::index()'s equivalent panel
 * query, and ProductVariant::totalStock()/isLowStock() for the stock
 * math this mirrors rather than reimplements). Explicitly scoped by the
 * server-supplied $tenantId — see OrderLookupTool's docblock for why.
 */
class ProductLookupTool implements AiTool
{
    public function name(): string
    {
        return 'lookup_products';
    }

    public function description(): string
    {
        return "Search the current store's products by name, and/or filter to only active ones. Read-only. Returns each matching product's variants with price and current stock quantity.";
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Product name to search for (partial match)'],
                'active_only' => ['type' => 'boolean', 'description' => 'When true, only include active products (default true)'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum number of products to return (default 5, max 20)'],
            ],
        ];
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function handle(int $tenantId, array $args): array
    {
        $limit = min(max((int) ($args['limit'] ?? 5), 1), 20);
        $activeOnly = ! array_key_exists('active_only', $args) || (bool) $args['active_only'];

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when(! empty($args['name']), fn ($q) => $q->where('name', 'like', '%'.$args['name'].'%'))
            ->when($activeOnly, fn ($q) => $q->where('is_active', 1))
            ->with(['category', 'variants'])
            ->latest()
            ->limit($limit)
            ->get();

        return [
            'count' => $products->count(),
            'products' => $products->map(fn (Product $product) => [
                'name' => $product->name,
                'category' => $product->category?->name,
                'is_active' => (bool) $product->is_active,
                'variants' => $product->variants->map(fn ($variant) => [
                    'variant_name' => $variant->variant_name,
                    'sku' => $variant->sku,
                    'selling_price' => (float) $variant->selling_price,
                    'purchase_price' => (float) $variant->purchase_price,
                    'stock_quantity' => $variant->totalStock(),
                    'is_low_stock' => $variant->isLowStock(),
                ])->all(),
            ])->all(),
        ];
    }
}
