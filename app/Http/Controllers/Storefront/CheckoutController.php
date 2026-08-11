<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\IncompleteOrder;
use App\Models\Inventory;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\DeliveryChargeService;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function place(Request $request, DeliveryChargeService $deliveryChargeService)
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

        $variants = ProductVariant::with('product')->whereIn('id', array_keys($cart))->get();

        $deliveryCharge = $deliveryChargeService->calculate((int) $data['division_id']);

        $order = DB::transaction(function () use ($data, $cart, $variants, $deliveryCharge) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'],
                    'division_id' => $data['division_id'], 'district_id' => $data['district_id']]
            );

            $subtotal = $variants->sum(fn ($v) => $v->selling_price * $cart[$v->id]);

            $order = Order::create([
                'source' => 'web',
                'customer_id' => $customer->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_address' => $data['customer_address'],
                'division_id' => $data['division_id'],
                'district_id' => $data['district_id'],
                'subtotal' => $subtotal,
                'delivery_charge' => $deliveryCharge,
                'total' => $subtotal + $deliveryCharge,
                'payment_method' => 'cod',
                'status' => 'pending',
                'note' => $data['note'] ?? null,
                'fb_event_id' => (string) Str::uuid(),
            ]);

            $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

            foreach ($variants as $v) {
                $qty = $cart[$v->id];

                $order->items()->create([
                    'tenant_id' => $order->tenant_id,
                    'variant_id' => $v->id,
                    'product_name' => $v->product->name,
                    'variant_name' => $v->variant_name,
                    'sku' => $v->sku,
                    'unit_price' => $v->selling_price,
                    'purchase_price' => $v->purchase_price,
                    'quantity' => $qty,
                    'line_total' => $v->selling_price * $qty,
                ]);

                if ($warehouse) {
                    Inventory::where('variant_id', $v->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->decrement('quantity', $qty);

                    StockMovement::create([
                        'variant_id' => $v->id,
                        'warehouse_id' => $warehouse->id,
                        'type' => 'sale',
                        'quantity' => -$qty,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                    ]);
                }
            }

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });

        // clear cart + mark incomplete order recovered
        session()->forget($this->cartKey());
        IncompleteOrder::where('session_key', session()->getId())
            ->update(['status' => 'recovered', 'recovered_order_id' => $order->id]);

        $this->sendCapiPurchase($order, $request);

        return redirect()->route('storefront.order.success', $order->order_number);
    }

    /** Server-side Purchase event (deduplicated with the browser pixel via fb_event_id). */
    protected function sendCapiPurchase(Order $order, Request $request): void
    {
        $mk = MarketingSetting::first();

        if (! $mk || ! $mk->fb_pixel_id || ! $mk->fb_capi_token) {
            return;
        }

        try {
            $order->load('items');

            (new MetaCapiService($mk->fb_pixel_id, $mk->fb_capi_token, $mk->fb_test_event_code))
                ->sendPurchase(
                    $order,
                    $request->ip(),
                    $request->userAgent(),
                    $request->cookie('_fbp'),
                    $request->cookie('_fbc'),
                );
        } catch (\Throwable $e) {
            // never break checkout because of an ad-tracking failure
            report($e);
        }
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
