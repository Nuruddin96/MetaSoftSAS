<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\IncompleteOrder;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\DeliveryChargeService;
use App\Services\Storefront\OrderPlacementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    protected function cartKey(): string
    {
        return 'cart_'.app('currentTenant')->id;
    }

    public function show(DeliveryChargeService $deliveryCharge)
    {
        $cart = session($this->cartKey(), []);
        if (empty($cart)) {
            return redirect()->route('storefront.home');
        }

        $variants = ProductVariant::with('product')->whereIn('id', array_keys($cart))->get();
        $items = $variants->map(fn ($v) => [
            'variant' => $v, 'qty' => $cart[$v->id], 'total' => $cart[$v->id] * $v->selling_price,
        ]);

        return view('storefront.checkout', array_merge([
            'tenant' => app('currentTenant'),
            'items' => $items,
            'subtotal' => $items->sum('total'),
            'divisions' => DB::table('bd_divisions')->orderBy('id')->get(),
            'districts' => DB::table('bd_districts')->orderBy('name')->get(),
        ], $deliveryCharge->chargesForView()));
    }

    /** AJAX: save half-filled checkout as incomplete order.
     *  Every abandoned attempt is kept as its own record (never overwritten),
     *  so the tenant sees the full history, not just the latest one. */
    public function trackIncomplete(Request $request)
    {
        $cart = session($this->cartKey(), []);
        if (empty($cart) || ! $request->filled('customer_phone')) {
            return response()->json(['ok' => true]);
        }

        // avoid spamming duplicate rows within the same minute for the same session+phone
        $recent = IncompleteOrder::where('session_key', session()->getId())
            ->where('customer_phone', $request->input('customer_phone'))
            ->where('last_activity_at', '>=', now()->subMinutes(2))
            ->first();

        if ($recent) {
            $recent->update([
                'customer_name' => $request->input('customer_name'),
                'customer_address' => $request->input('customer_address'),
                'cart_json' => $cart,
                'last_activity_at' => now(),
            ]);
        } else {
            IncompleteOrder::create([
                'session_key' => session()->getId(),
                'customer_name' => $request->input('customer_name'),
                'customer_phone' => $request->input('customer_phone'),
                'customer_address' => $request->input('customer_address'),
                'cart_json' => $cart,
                'total' => 0,
                'status' => 'abandoned',
                'last_activity_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function place(Request $request, OrderPlacementService $placement)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'required|string|max:1000',
            'division_id' => 'required|integer|exists:bd_divisions,id',
            'district_id' => 'required|integer|exists:bd_districts,id',
            'note' => 'nullable|string|max:500',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        $cart = session($this->cartKey(), []);
        abort_if(empty($cart), 400);

        // Tenant-scoped via ProductVariant's own BelongsToTenant global
        // scope (app('currentTenant') is bound on every storefront
        // request) — any other tenant's id that ended up in this session's
        // cart array simply isn't returned here, the same protection
        // CartController::index() already relies on.
        $variants = ProductVariant::with('product')->whereIn('id', array_keys($cart))->get();

        if ($variants->count() !== count($cart)) {
            return back()->with('error', 'কার্টের কিছু প্রোডাক্ট আর পাওয়া যাচ্ছে না। কার্ট চেক করুন।');
        }

        $lines = $variants->map(fn ($v) => ['variant' => $v, 'qty' => $cart[$v->id]]);

        try {
            $order = $placement->place($lines, $data, 'web');
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'insufficient_stock:')) {
                $productName = substr($e->getMessage(), strlen('insufficient_stock:'));

                return back()->withInput()->with('error', "দুঃখিত, \"{$productName}\" এখন পর্যাপ্ত স্টকে নেই। কার্ট চেক করুন।");
            }

            if (str_starts_with($e->getMessage(), 'inactive_variant:')) {
                $productName = substr($e->getMessage(), strlen('inactive_variant:'));

                return back()->withInput()->with('error', "দুঃখিত, \"{$productName}\" এখন পাওয়া যাচ্ছে না। কার্ট চেক করুন।");
            }

            throw $e;
        }

        // clear cart + mark incomplete order recovered
        session()->forget($this->cartKey());
        IncompleteOrder::where('session_key', session()->getId())
            ->update(['status' => 'recovered', 'recovered_order_id' => $order->id]);

        $placement->sendCapiPurchase($order, $request);

        return redirect()->route('storefront.order.success', $order->order_number);
    }

    public function success(string $orderNumber)
    {
        $order = Order::with('items')->where('order_number', $orderNumber)->firstOrFail();

        return view('storefront.success', [
            'tenant' => app('currentTenant'),
            'order' => $order,
        ]);
    }
}
