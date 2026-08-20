<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\IncompleteOrderResource;
use App\Models\IncompleteOrder;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class IncompleteOrderController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('currentTenant');

        $items = IncompleteOrder::where('tenant_id', $tenant->id)
            ->where('status', '!=', 'recovered')
            ->latest('last_activity_at')->paginate(25);

        // Mirrors Tenant\IncompleteOrderController::index()'s product-name
        // resolution exactly — cart_json keys are variant ids.
        $allIds = collect($items->items())->flatMap(fn ($i) => array_keys($i->cart_json ?? []))->unique();
        $variantNames = ProductVariant::with('product')->whereIn('id', $allIds)->get()
            ->mapWithKeys(fn ($v) => [$v->id => $v->product->name])->all();

        return response()->json([
            'data' => $items->getCollection()->map(fn ($item) => (new IncompleteOrderResource($item, $variantNames))->toArray($request)),
        ]);
    }

    public function updateStatus(Request $request, int $incompleteOrder)
    {
        $tenant = app('currentTenant');
        // Only these 3 are staff-settable — 'recovered' happens via a
        // separate real-recovery flow elsewhere, never a manual status
        // PATCH, matching Tenant\IncompleteOrderController::updateStatus() exactly.
        $data = $request->validate(['status' => 'required|in:abandoned,contacted,discarded']);

        $item = IncompleteOrder::where('tenant_id', $tenant->id)->findOrFail($incompleteOrder);
        $item->update(['status' => $data['status']]);

        return response()->json((new IncompleteOrderResource($item))->toArray($request));
    }
}
