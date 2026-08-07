<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $variants = ProductVariant::with(['product', 'inventory.warehouse'])
            ->whereHas('product', function ($q) use ($request) {
                if ($request->q) $q->where('name', 'like', '%' . $request->q . '%');
            })
            ->paginate(30)->withQueryString();

        return view('tenant.inventory', [
            'variants'   => $variants,
            'warehouses' => Warehouse::all(),
        ]);
    }

    public function lowStock()
    {
        $variants = ProductVariant::with(['product', 'inventory'])->get()
            ->filter(fn ($v) => $v->isLowStock())
            ->sortBy(fn ($v) => $v->totalStock());

        return view('tenant.low-stock', compact('variants'));
    }

    public function adjust(Request $request)
    {
        $data = $request->validate([
            'variant_id'   => 'required|exists:product_variants,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity'     => 'required|integer',
            'note'         => 'nullable|string|max:255',
        ]);

        $inv = Inventory::firstOrCreate(
            ['variant_id' => $data['variant_id'], 'warehouse_id' => $data['warehouse_id']],
            ['quantity' => 0]
        );

        $inv->increment('quantity', $data['quantity']);

        StockMovement::create([
            'variant_id'   => $data['variant_id'],
            'warehouse_id' => $data['warehouse_id'],
            'type'         => 'adjustment',
            'quantity'     => $data['quantity'],
            'note'         => $data['note'] ?? null,
            'user_id'      => auth('tenant')->id(),
        ]);

        return back()->with('success', 'স্টক আপডেট হয়েছে।');
    }
}
