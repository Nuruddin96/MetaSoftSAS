<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Courier\CourierManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function create()
    {
        $products = Product::with('variants')->where('is_active', 1)->orderBy('name')->get();

        $productsJson = $products->map(fn ($p) => [
            'name' => $p->name,
            'variants' => $p->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->variant_name,
                'price' => (float) $v->selling_price,
            ])->values(),
        ])->values();

        return view('tenant.orders.create', [
            'productsJson' => $productsJson,
            'divisions' => DB::table('bd_divisions')->orderBy('id')->get(),
            'districts' => DB::table('bd_districts')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'nullable|string|max:1000',
            'division_id' => 'nullable|integer|exists:bd_divisions,id',
            'district_id' => 'nullable|integer|exists:bd_districts,id',
            'channel' => 'required|in:website,facebook,instagram,whatsapp,call,others',
            'payment_method' => 'required|in:cod,cash,bkash,nagad,bank',
            'delivery_charge' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'required|exists:product_variants,id',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        $variants = ProductVariant::with('product')->whereIn('id', $data['variant_ids'])->get()->keyBy('id');

        $order = DB::transaction(function () use ($data, $variants) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null,
                    'division_id' => $data['division_id'] ?? null, 'district_id' => $data['district_id'] ?? null]
            );

            $subtotal = 0;
            foreach ($data['variant_ids'] as $i => $vid) {
                $qty = $data['quantities'][$i] ?? 1;
                $subtotal += $variants[$vid]->selling_price * $qty;
            }
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $deliveryCharge = (float) ($data['delivery_charge'] ?? 0);

            $order = Order::create([
                'source' => 'manual',
                'channel' => $data['channel'],
                'customer_id' => $customer->id,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_address' => $data['customer_address'] ?? null,
                'division_id' => $data['division_id'] ?? null,
                'district_id' => $data['district_id'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'delivery_charge' => $deliveryCharge,
                'total' => $subtotal - $discount + $deliveryCharge,
                'payment_method' => $data['payment_method'],
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'note' => $data['note'] ?? null,
                'fb_event_id' => (string) Str::uuid(),
            ]);

            $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

            foreach ($data['variant_ids'] as $i => $vid) {
                $v = $variants[$vid];
                $qty = $data['quantities'][$i] ?? 1;

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
                    Inventory::where('variant_id', $v->id)->where('warehouse_id', $warehouse->id)
                        ->decrement('quantity', $qty);

                    StockMovement::create([
                        'variant_id' => $v->id, 'warehouse_id' => $warehouse->id,
                        'type' => 'sale', 'quantity' => -$qty,
                        'reference_type' => 'order', 'reference_id' => $order->id,
                        'user_id' => auth('tenant')->id(),
                    ]);
                }
            }

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });

        return redirect()->route('tenant.orders.show', $order)->with('success', 'অর্ডার তৈরি হয়েছে — '.$order->order_number);
    }

    public function index(Request $request)
    {
        $orders = Order::with('items')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->q, function ($q) use ($request) {
                $q->where(fn ($qq) => $qq
                    ->where('order_number', 'like', '%'.$request->q.'%')
                    ->orWhere('customer_phone', 'like', '%'.$request->q.'%')
                    ->orWhere('customer_name', 'like', '%'.$request->q.'%'));
            })
            ->latest()->paginate(20)->withQueryString();

        return view('tenant.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load('items');

        return view('tenant.orders.show', compact('order'));
    }

    public function updateStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled,returned',
        ]);

        $order->update([
            'status' => $data['status'],
            'confirmed_at' => $data['status'] === 'confirmed' ? now() : $order->confirmed_at,
            'delivered_at' => $data['status'] === 'delivered' ? now() : $order->delivered_at,
        ]);

        return back()->with('success', 'অর্ডার স্ট্যাটাস আপডেট হয়েছে।');
    }

    public function updateChannel(Request $request, Order $order)
    {
        $data = $request->validate([
            'channel' => 'required|in:website,facebook,instagram,whatsapp,call,others',
        ]);

        $order->update(['channel' => $data['channel']]);

        return back()->with('success', 'অর্ডারের উৎস আপডেট হয়েছে।');
    }

    /** Bulk: change status for many orders at once */
    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|in:pending,confirmed,processing,shipped,delivered,cancelled,returned',
        ]);

        $count = Order::whereIn('id', $data['order_ids'])->update(['status' => $data['status']]);

        return back()->with('success', "$count টি অর্ডারের স্ট্যাটাস আপডেট হয়েছে।");
    }

    /** Bulk: send many orders to a courier at once */
    public function bulkCourier(Request $request)
    {
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'provider' => 'required|in:steadfast,pathao',
        ]);

        $service = CourierManager::forProvider($data['provider']);

        if (! $service) {
            return back()->with('error', 'কুরিয়ারের API সেটিংস পাওয়া যায়নি।');
        }

        $orders = Order::whereIn('id', $data['order_ids'])
            ->whereNull('courier_consignment_id')->with('items')->get();

        $sent = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $result = $service->createShipment($order);
                $order->update([
                    'courier_provider' => $data['provider'],
                    'courier_consignment_id' => $result['consignment_id'],
                    'courier_tracking_code' => $result['tracking_code'],
                    'courier_status' => 'pending',
                    'status' => $order->status === 'pending' ? 'processing' : $order->status,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $msg = "$sent টি অর্ডার কুরিয়ারে পাঠানো হয়েছে।";
        if ($failed) {
            $msg .= " $failed টি ব্যর্থ হয়েছে।";
        }

        return back()->with($failed && ! $sent ? 'error' : 'success', $msg);
    }
}
