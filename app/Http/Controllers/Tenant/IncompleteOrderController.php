<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\IncompleteOrder;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class IncompleteOrderController extends Controller
{
    public function index()
    {
        $items = IncompleteOrder::where('status', '!=', 'recovered')
            ->latest('last_activity_at')->paginate(25);

        // attach product names for display
        $allIds = collect($items->items())->flatMap(fn ($i) => array_keys($i->cart_json ?? []))->unique();
        $variants = ProductVariant::with('product')->whereIn('id', $allIds)->get()->keyBy('id');

        return view('tenant.incomplete', compact('items', 'variants'));
    }

    public function updateStatus(Request $request, IncompleteOrder $incompleteOrder)
    {
        $data = $request->validate(['status' => 'required|in:abandoned,contacted,discarded']);

        $incompleteOrder->update(['status' => $data['status']]);

        return back()->with('success', 'স্ট্যাটাস আপডেট হয়েছে।');
    }

    public function destroy(IncompleteOrder $incompleteOrder)
    {
        $incompleteOrder->delete();

        return back()->with('success', 'এন্ট্রিটি মুছে ফেলা হয়েছে।');
    }
}
