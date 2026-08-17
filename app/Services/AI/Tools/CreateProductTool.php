<?php

namespace App\Services\AI\Tools;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new product with a single default variant. HIGH RISK — see
 * AiMutatingTool docblock. Deliberately a simplified subset of
 * Tenant\ProductController::store(): no image upload (no file-input
 * concept in a text chat) and single-variant only — a tenant who needs
 * multi-variant products or images still uses the normal Products page;
 * this tool is for the common "quickly add one product" case.
 */
class CreateProductTool implements AiMutatingTool
{
    public function name(): string
    {
        return 'create_product';
    }

    public function description(): string
    {
        return 'Creates a new product with a single variant (name, price, optional category and starting stock). HIGH RISK — only proposes the product for the store owner to explicitly confirm; never executed immediately. For multi-variant products or ones needing photos, tell the user to use the Products page instead.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'category_name' => ['type' => 'string', 'description' => 'Existing category name — omit if unsure'],
                'selling_price' => ['type' => 'number'],
                'purchase_price' => ['type' => 'number'],
                'initial_stock' => ['type' => 'integer', 'description' => 'Starting stock quantity, default 0'],
            ],
            'required' => ['name', 'selling_price'],
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function preview(int $tenantId, array $args): array
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return ['error' => 'প্রোডাক্টের নাম দিতে হবে।'];
        }

        if (! is_numeric($args['selling_price'] ?? null) || (float) $args['selling_price'] < 0) {
            return ['error' => 'সঠিক বিক্রয় মূল্য দিতে হবে।'];
        }

        $sellingPrice = (float) $args['selling_price'];
        $purchasePrice = is_numeric($args['purchase_price'] ?? null) ? (float) $args['purchase_price'] : 0.0;
        $stock = max(0, (int) ($args['initial_stock'] ?? 0));

        $categoryId = null;
        $categoryName = trim((string) ($args['category_name'] ?? ''));

        if ($categoryName !== '') {
            $category = Category::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('name', 'like', '%'.$categoryName.'%')
                ->first();
            $categoryId = $category?->id;
        }

        $resolvedArgs = [
            'name' => $name,
            'category_id' => $categoryId,
            'selling_price' => $sellingPrice,
            'purchase_price' => $purchasePrice,
            'initial_stock' => $stock,
        ];

        $summary = "নতুন প্রোডাক্ট তৈরি হবে:\n"
            ."নাম: {$name}\n"
            .($categoryId ? "ক্যাটাগরি: {$categoryName}\n" : ($categoryName !== '' ? "ক্যাটাগরি: \"{$categoryName}\" পাওয়া যায়নি — ক্যাটাগরি ছাড়াই তৈরি হবে\n" : ''))
            .'বিক্রয় মূল্য: ৳'.number_format($sellingPrice, 2)."\n"
            .'শুরুর স্টক: '.$stock;

        return ['summary' => $summary, 'resolved_args' => $resolvedArgs];
    }

    public function handle(int $tenantId, array $args): array
    {
        $product = DB::transaction(function () use ($tenantId, $args) {
            $product = Product::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'name' => $args['name'],
                'category_id' => $args['category_id'] ?? null,
                'has_variants' => 0,
                'is_active' => 1,
            ]);

            $variant = $product->variants()->create([
                'tenant_id' => $tenantId,
                'variant_name' => 'Default',
                'purchase_price' => $args['purchase_price'] ?? 0,
                'selling_price' => $args['selling_price'],
            ]);

            $warehouse = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_default', 1)->first()
                ?? Warehouse::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();

            if ($warehouse) {
                $stock = (int) ($args['initial_stock'] ?? 0);

                Inventory::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'variant_id' => $variant->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => $stock,
                ]);

                if ($stock > 0) {
                    StockMovement::withoutGlobalScopes()->create([
                        'tenant_id' => $tenantId,
                        'variant_id' => $variant->id,
                        'warehouse_id' => $warehouse->id,
                        'type' => 'purchase',
                        'quantity' => $stock,
                        'reference_type' => 'initial',
                        'user_id' => auth('tenant')->id(),
                    ]);
                }
            }

            return $product;
        });

        return [
            'success' => true,
            'message' => "প্রোডাক্ট তৈরি হয়েছে — {$product->name}",
            'product_name' => $product->name,
        ];
    }
}
