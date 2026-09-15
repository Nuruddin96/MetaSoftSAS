<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DueLedger;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors Tenant\PosController's real capability exactly — scan
 * (barcode/SKU lookup) and sell (complete a sale: creates a real
 * source='pos' Order, decrements Inventory, records a StockMovement,
 * updates Customer due/spend). Same plan gate
 * ($tenant->plan?->allow_pos) as the web version — this is a real,
 * fully-built backend workflow, not invented for mobile.
 */
class PosController extends Controller
{
    public function scan(string $code)
    {
        $variant = ProductVariant::with('product')
            ->where('barcode', $code)
            ->orWhere('sku', $code)
            ->first();

        if (! $variant) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'id' => $variant->id,
            'name' => $variant->product->name,
            'variant' => $variant->variant_name,
            'price' => (float) $variant->selling_price,
            'stock' => $variant->totalStock(),
        ]);
    }

    public function sell(Request $request)
    {
        $tenant = app('currentTenant');
        abort_unless($tenant->plan?->allow_pos, 403, 'POS ফিচারটি আপনার প্ল্যানে নেই।');

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.variant_id' => 'required|exists:product_variants,id',
            'items.*.qty' => 'required|integer|min:1',
            'discount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|in:cash,due',
            'paid_amount' => 'nullable|numeric|min:0',
            'customer_name' => 'required_if:payment_method,due|nullable|string|max:150',
            'customer_phone' => 'required_if:payment_method,due|nullable|regex:/^01[3-9][0-9]{8}$/',
        ], [
            'customer_phone.required_if' => 'বাকিতে বিক্রির জন্য কাস্টমারের ফোন নাম্বার লাগবে।',
            'customer_name.required_if' => 'বাকিতে বিক্রির জন্য কাস্টমারের নাম লাগবে।',
        ]);

        $variantIds = collect($data['items'])->pluck('variant_id');
        $variants = ProductVariant::with('product')->whereIn('id', $variantIds)->get()->keyBy('id');

        $order = DB::transaction(function () use ($data, $variants) {
            $subtotal = collect($data['items'])->sum(
                fn ($i) => $variants[$i['variant_id']]->selling_price * $i['qty']
            );
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $total = $subtotal - $discount;

            $customer = null;
            if (! empty($data['customer_phone'])) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $data['customer_phone']],
                    ['name' => $data['customer_name'] ?? 'POS কাস্টমার']
                );
            }

            $isDue = $data['payment_method'] === 'due';
            $paid = $isDue ? min((float) ($data['paid_amount'] ?? 0), $total) : $total;
            $due = $total - $paid;

            $order = Order::create([
                'source' => 'pos',
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name ?? 'ওয়াক-ইন কাস্টমার',
                'customer_phone' => $customer?->phone ?? '',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'payment_method' => $isDue ? 'due' : 'cash',
                'payment_status' => $due > 0 ? ($paid > 0 ? 'partial' : 'unpaid') : 'paid',
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

            foreach ($data['items'] as $item) {
                $v = $variants[$item['variant_id']];

                $order->items()->create([
                    'tenant_id' => $order->tenant_id,
                    'variant_id' => $v->id,
                    'product_name' => $v->product->name,
                    'variant_name' => $v->variant_name,
                    'sku' => $v->sku,
                    'unit_price' => $v->selling_price,
                    'purchase_price' => $v->purchase_price,
                    'quantity' => $item['qty'],
                    'line_total' => $v->selling_price * $item['qty'],
                ]);

                if ($warehouse) {
                    Inventory::where('variant_id', $v->id)->where('warehouse_id', $warehouse->id)
                        ->decrement('quantity', $item['qty']);

                    StockMovement::create([
                        'variant_id' => $v->id, 'warehouse_id' => $warehouse->id,
                        'type' => 'pos_sale', 'quantity' => -$item['qty'],
                        'reference_type' => 'order', 'reference_id' => $order->id,
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            if ($customer) {
                $customer->increment('total_orders');
                $customer->increment('total_spent', $total);

                if ($due > 0) {
                    $customer->increment('due_balance', $due);
                    DueLedger::create([
                        'customer_id' => $customer->id,
                        'order_id' => $order->id,
                        'type' => 'due',
                        'amount' => $due,
                        'balance_after' => $customer->fresh()->due_balance,
                        'note' => 'POS বিক্রি '.$order->order_number,
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            return $order;
        });

        $order->load('items');

        return response()->json([
            'ok' => true,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'created_at' => $order->created_at,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'paid_amount' => (float) $order->paid_amount,
            'due_amount' => (float) $order->due_amount,
            // Web/Flutter parity project — mirrors tenant/pos-receipt.blade.php's
            // itemized table (Tenant\PosController::receipt()). Physical
            // thermal-printer output stays out of scope, same as Barcode
            // printing elsewhere in this app — this is the same receipt
            // *content*, rendered as a native screen instead of a printable
            // web page.
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
        ], 201);
    }
}
