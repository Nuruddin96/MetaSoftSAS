<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\MessengerMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Courier\CourierManager;
use App\Services\DeliveryChargeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function create(DeliveryChargeService $deliveryCharge)
    {
        return view('tenant.orders.create', array_merge([
            'productsJson' => $this->productsJson(),
            'divisions' => DB::table('bd_divisions')->orderBy('id')->get(),
            'districts' => DB::table('bd_districts')->orderBy('name')->get(),
        ], $deliveryCharge->chargesForView()));
    }

    /** Active products+variants, shaped for the product/variant picker JS shared by create.blade.php and show.blade.php. */
    protected function productsJson()
    {
        return Product::with('variants')->where('is_active', 1)->orderBy('name')->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->variant_name,
                    'price' => (float) $v->selling_price,
                ])->values(),
            ])->values();
    }

    public function store(Request $request, DeliveryChargeService $deliveryChargeService)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'nullable|string|max:1000',
            'division_id' => 'nullable|integer|exists:bd_divisions,id',
            'district_id' => 'nullable|integer|exists:bd_districts,id',
            'channel' => 'required|in:website,facebook,instagram,whatsapp,call,others',
            'payment_method' => 'required|in:cod,cash,bkash,nagad,bank',
            // delivery_charge is deliberately NOT an accepted input — the
            // New Order form no longer has a manual field for it at all
            // (see create.blade.php). The server always computes it from
            // division_id via DeliveryChargeService below; a client can no
            // longer influence the charged amount by submitting a value.
            'discount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'required|exists:product_variants,id',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        // See complete()'s identical guard below for why this check exists —
        // 'exists:product_variants,id' above is an unscoped raw DB check.
        $variants = ProductVariant::with('product')->whereIn('id', $data['variant_ids'])->get()->keyBy('id');

        if ($variants->count() !== count(array_unique($data['variant_ids']))) {
            return back()->withErrors(['variant_ids' => 'একটি বা একাধিক প্রোডাক্ট পাওয়া যায়নি।'])->withInput();
        }

        $order = DB::transaction(function () use ($data, $variants, $deliveryChargeService) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null,
                    'division_id' => $data['division_id'] ?? null, 'district_id' => $data['district_id'] ?? null]
            );

            $subtotal = $this->calcSubtotal($variants, $data['variant_ids'], $data['quantities']);
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $deliveryCharge = $deliveryChargeService->calculate($data['division_id'] ?? null);

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

            $this->attachItems($order, $variants, $data['variant_ids'], $data['quantities']);

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });

        return redirect()->route('tenant.orders.show', $order)->with('success', 'অর্ডার তৈরি হয়েছে — '.$order->order_number);
    }

    /**
     * Completes an Order that already exists with no items yet — namely one
     * auto-created from a Messenger conversation the moment a valid phone
     * number was detected (see MessengerWebhookController::maybeCreatePendingOrder()).
     * The operator picks product/variant/qty/price here; this is NOT a
     * second order-creation path — same Order row, same order_number,
     * transitions pending -> confirmed exactly like a fresh manual order
     * does in store() above.
     */
    public function complete(Request $request, Order $order, DeliveryChargeService $deliveryChargeService)
    {
        // Defense-in-depth: implicit route-model binding on {order} is
        // expected to already be scoped to the current tenant via Order's
        // BelongsToTenant global scope, but this explicit check is kept
        // regardless — never trust a bound model's tenant_id implicitly for
        // an action this consequential (attaching items + decrementing
        // inventory). See PROJECT_KNOWLEDGE.md-style reasoning already used
        // for resolveReplyToken() in MessengerInboxController.
        abort_if(! app()->bound('currentTenant') || $order->tenant_id !== app('currentTenant')->id, 404);

        abort_if($order->status !== 'pending' || $order->items()->exists(), 409);

        $data = $request->validate([
            'customer_name' => 'nullable|string|max:150',
            'customer_phone' => 'nullable|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'nullable|string|max:1000',
            'division_id' => 'nullable|integer|exists:bd_divisions,id',
            'district_id' => 'nullable|integer|exists:bd_districts,id',
            'payment_method' => 'required|in:cod,cash,bkash,nagad,bank',
            // Same "no manual delivery_charge input" rule as store() — see
            // its comment. The confirm form no longer submits this field.
            'discount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
            'variant_ids' => 'required|array|min:1',
            'variant_ids.*' => 'required|exists:product_variants,id',
            'quantities' => 'required|array|min:1',
            'quantities.*' => 'required|integer|min:1',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        // ProductVariant is tenant-scoped (BelongsToTenant) so this query
        // already can't return another tenant's variant — but the
        // 'exists:product_variants,id' validation rule above is a raw,
        // UNSCOPED DB check that would happily accept a foreign tenant's
        // variant id. Catch that mismatch explicitly here with a clean
        // validation error instead of silently mis-computing totals or
        // crashing on a missing array key further down.
        $variants = ProductVariant::with('product')->whereIn('id', $data['variant_ids'])->get()->keyBy('id');

        if ($variants->count() !== count(array_unique($data['variant_ids']))) {
            return back()->withErrors(['variant_ids' => 'একটি বা একাধিক প্রোডাক্ট পাওয়া যায়নি।'])->withInput();
        }

        DB::transaction(function () use ($order, $data, $variants, $deliveryChargeService) {
            $subtotal = $this->calcSubtotal($variants, $data['variant_ids'], $data['quantities']);
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            // Effective division: whatever was just submitted, falling back
            // to whatever the order already had (e.g. from a Messenger
            // conversation's extracted address) — so confirming without
            // touching the division field still charges correctly rather
            // than silently reverting to the "outside Dhaka" default.
            $effectiveDivisionId = $data['division_id'] ?? $order->division_id;
            $deliveryCharge = $deliveryChargeService->calculate($effectiveDivisionId);

            $order->update(array_filter([
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                // Messenger-created pending orders had no way to record
                // these before — the confirm form only ever collected name/
                // phone/address text. Nullable/optional, same as the three
                // fields above: staff fills in whatever was missing.
                'division_id' => $data['division_id'] ?? null,
                'district_id' => $data['district_id'] ?? null,
            ]) + [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'delivery_charge' => $deliveryCharge,
                'total' => $subtotal - $discount + $deliveryCharge,
                'payment_method' => $data['payment_method'],
                'note' => $data['note'] ?? $order->note,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            $this->attachItems($order, $variants, $data['variant_ids'], $data['quantities']);

            $customer = $order->customer;
            $customer?->increment('total_orders');
            $customer?->increment('total_spent', $order->total);
        });

        return redirect()->route('tenant.orders.show', $order)->with('success', 'অর্ডার কনফার্ম হয়েছে — '.$order->order_number);
    }

    /** Subtotal preview for a set of variant/qty pairs — no writes. */
    protected function calcSubtotal($variants, array $variantIds, array $quantities): float
    {
        $subtotal = 0;
        foreach ($variantIds as $i => $vid) {
            $subtotal += $variants[$vid]->selling_price * ($quantities[$i] ?? 1);
        }

        return $subtotal;
    }

    /**
     * Attaches OrderItems for the given variant/qty pairs, decrementing
     * inventory and logging stock movements — shared by store() (brand new
     * order) and complete() (an existing pending order getting its items
     * for the first time).
     */
    protected function attachItems(Order $order, $variants, array $variantIds, array $quantities): void
    {
        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

        foreach ($variantIds as $i => $vid) {
            $v = $variants[$vid];
            $qty = $quantities[$i] ?? 1;

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
    }

    public function index(Request $request)
    {
        $orders = Order::with('items')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->courier === 'pending', fn ($q) => $q->whereNotNull('courier_consignment_id')
                ->whereNotIn('status', ['delivered', 'cancelled', 'returned']))
            ->when($request->q, function ($q) use ($request) {
                $q->where(fn ($qq) => $qq
                    ->where('order_number', 'like', '%'.$request->q.'%')
                    ->orWhere('customer_phone', 'like', '%'.$request->q.'%')
                    ->orWhere('customer_name', 'like', '%'.$request->q.'%'));
            })
            ->latest()->paginate(20)->withQueryString();

        return view('tenant.orders.index', compact('orders'));
    }

    public function show(Order $order, DeliveryChargeService $deliveryCharge)
    {
        $order->load('items');

        $messengerMessages = $order->messenger_psid
            ? MessengerMessage::where('sender_psid', $order->messenger_psid)->orderBy('created_at')->get()
            : collect();

        return view('tenant.orders.show', array_merge([
            'order' => $order,
            'messengerMessages' => $messengerMessages,
            'productsJson' => $order->items->isEmpty() ? $this->productsJson() : collect(),
            // Only meaningful for the pending-order complete form (see
            // show.blade.php's @else branch) but harmless/unused otherwise —
            // cheaper to always pass than to conditionally build two
            // different view-data shapes for one controller action.
            'divisions' => DB::table('bd_divisions')->orderBy('id')->get(),
            'districts' => DB::table('bd_districts')->orderBy('name')->get(),
        ], $deliveryCharge->chargesForView()));
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

        // Same two guards as CourierController::send() — never re-send an
        // order that's already been dispatched, and never push an order
        // that's already in a final state (delivered/cancelled/returned).
        $orders = Order::whereIn('id', $data['order_ids'])
            ->whereNull('courier_consignment_id')
            ->whereNotIn('status', ['delivered', 'cancelled', 'returned'])
            ->with('items')->get();

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
                Log::warning('bulkCourier: failed to create shipment for one order.', [
                    'order_id' => $order->id,
                    'provider' => $data['provider'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $msg = "$sent টি অর্ডার কুরিয়ারে পাঠানো হয়েছে।";
        if ($failed) {
            $msg .= " $failed টি ব্যর্থ হয়েছে।";
        }

        return back()->with($failed && ! $sent ? 'error' : 'success', $msg);
    }
}
