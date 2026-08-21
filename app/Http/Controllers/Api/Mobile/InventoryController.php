<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mirrors Tenant\InventoryController's real capability: index (paginated,
 * searchable, product+variant+per-warehouse stock) and adjust() — the
 * SAME increment-by-delta business rule
 * (`Inventory::firstOrCreate(...)->increment('quantity', $delta)` +
 * `StockMovement::create(['type' => 'adjustment', ...])`), reused
 * unmodified rather than duplicated. `lowStock()` is folded into index()
 * via a `low_stock=1` query param instead of a second route, same pattern
 * ProductCatalogController::index() already established — the underlying
 * rule (`ProductVariant::isLowStock()`) is identical either way.
 *
 * Rows are per VARIANT, not per product: `adjust()` operates on one
 * variant_id + warehouse_id pair (that's the real grain the backend
 * tracks stock at — see the `inventory` table's
 * `UNIQUE(variant_id, warehouse_id)` constraint), so the list must expose
 * variant_id for every row, and a multi-variant product appears as
 * multiple rows (one per variant) rather than one falsely-aggregated row
 * with no single variant_id to adjust.
 */
class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $variants = ProductVariant::with(['product', 'inventory.warehouse'])
            ->whereHas('product', function ($q) use ($request) {
                if ($request->filled('q')) {
                    $q->where('name', 'like', '%'.$request->string('q')->toString().'%');
                }
            })
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereRaw(
                '(select coalesce(sum(quantity), 0) from inventory where inventory.variant_id = product_variants.id) <= product_variants.low_stock_threshold'
            ))
            ->orderBy('product_id')
            ->paginate(30);

        return response()->json([
            'data' => $variants->getCollection()->map(fn (ProductVariant $v) => $this->present($v))->all(),
            'meta' => [
                'current_page' => $variants->currentPage(),
                'last_page' => $variants->lastPage(),
                'per_page' => $variants->perPage(),
                'total' => $variants->total(),
            ],
        ]);
    }

    public function adjust(Request $request)
    {
        $tenant = app('currentTenant');

        $data = $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', $tenant->id)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('tenant_id', $tenant->id)],
            'quantity' => 'required|integer',
            'note' => 'nullable|string|max:255',
        ]);

        $inv = Inventory::firstOrCreate(
            ['variant_id' => $data['variant_id'], 'warehouse_id' => $data['warehouse_id']],
            ['tenant_id' => $tenant->id, 'quantity' => 0]
        );

        $inv->increment('quantity', $data['quantity']);

        StockMovement::create([
            'tenant_id' => $tenant->id,
            'variant_id' => $data['variant_id'],
            'warehouse_id' => $data['warehouse_id'],
            'type' => 'adjustment',
            'quantity' => $data['quantity'],
            'note' => $data['note'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        $variant = ProductVariant::with(['product', 'inventory.warehouse'])->findOrFail($data['variant_id']);

        return response()->json($this->present($variant));
    }

    protected function present(ProductVariant $variant): array
    {
        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'product_name' => $variant->product?->name,
            'variant_name' => $variant->variant_name,
            'sku' => $variant->sku,
            'total_stock' => $variant->relationLoaded('inventory') ? (int) $variant->inventory->sum('quantity') : $variant->totalStock(),
            'is_low_stock' => $variant->relationLoaded('inventory') ? $this->isLowStockUsingLoadedInventory($variant) : $variant->isLowStock(),
            'warehouse_stock' => $variant->inventory->map(fn (Inventory $row) => [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => $row->warehouse?->name ?? 'Unknown',
                'quantity' => (int) $row->quantity,
            ])->values()->all(),
        ];
    }

    /**
     * Same rule as ProductVariant::isLowStock(), evaluated against the
     * already-eager-loaded `inventory` relation instead of a fresh
     * aggregate query per row — calling isLowStock() directly on a
     * paginated list would be a real N+1, same reasoning as
     * ProductVariant::stockCount()'s own doc comment.
     */
    protected function isLowStockUsingLoadedInventory(ProductVariant $variant): bool
    {
        return (int) $variant->inventory->sum('quantity') <= $variant->low_stock_threshold;
    }
}
