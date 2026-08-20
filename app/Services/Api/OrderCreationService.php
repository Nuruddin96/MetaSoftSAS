<?php

namespace App\Services\Api;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\DeliveryChargeService;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile-API equivalent of Tenant\OrderController::store()'s "manual new
 * order" flow — same validated-field shape, same DeliveryChargeService
 * call, same order-number generation (via Order::booted(), unchanged), same
 * item/inventory/stock-movement writes, same MetaCapiService purchase
 * event. Deliberately NOT wired into OrderController::store() itself (that
 * live web endpoint is left completely untouched to guarantee zero
 * behavior risk to the existing panel) — see the Phase-3 report for why
 * this is accepted as a documented, bounded duplication rather than a
 * refactor of a working checkout flow under time pressure.
 */
class OrderCreationService
{
    public function __construct(private DeliveryChargeService $deliveryChargeService)
    {
    }

    /**
     * @param  array{customer_name:string,customer_phone:string,customer_address:?string,division_id:?int,district_id:?int,channel:string,payment_method:string,order_date:?string,discount:?float,note:?string,variant_ids:int[],quantities:int[]}  $data
     */
    public function createFromManualEntry(array $data, ?int $actingUserId, ?string $clientIp, ?string $userAgent): Order
    {
        $variants = ProductVariant::with('product')->whereIn('id', $data['variant_ids'])->get()->keyBy('id');

        if ($variants->count() !== count(array_unique($data['variant_ids']))) {
            throw new \InvalidArgumentException('একটি বা একাধিক প্রোডাক্ট পাওয়া যায়নি।');
        }

        $order = DB::transaction(function () use ($data, $variants, $actingUserId) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                [
                    'name' => $data['customer_name'],
                    'address' => $data['customer_address'] ?? null,
                    'division_id' => $data['division_id'] ?? null,
                    'district_id' => $data['district_id'] ?? null,
                ]
            );

            $subtotal = $this->calcSubtotal($variants, $data['variant_ids'], $data['quantities']);
            $discount = min((float) ($data['discount'] ?? 0), $subtotal);
            $deliveryCharge = $this->deliveryChargeService->calculate($data['division_id'] ?? null);

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
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'note' => $data['note'] ?? null,
                'fb_event_id' => (string) Str::uuid(),
            ]);

            $this->attachItems($order, $variants, $data['variant_ids'], $data['quantities'], $actingUserId);

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });

        // Created directly as 'confirmed' (no separate pending stage for
        // this entry path), so this is always a genuine first confirmation
        // — matches OrderController::store()'s identical CAPI call site.
        MetaCapiService::sendPurchaseForOrder($order, $clientIp, $userAgent);

        return $order;
    }

    /** Subtotal preview for a set of variant/qty pairs — no writes. Mirrors OrderController::calcSubtotal(). */
    public function calcSubtotal($variants, array $variantIds, array $quantities): float
    {
        $subtotal = 0;
        foreach ($variantIds as $i => $vid) {
            $subtotal += $variants[$vid]->selling_price * ($quantities[$i] ?? 1);
        }

        return $subtotal;
    }

    /** Mirrors OrderController::attachItems(), with the acting user id passed in rather than resolved from a specific guard. */
    protected function attachItems(Order $order, $variants, array $variantIds, array $quantities, ?int $actingUserId): void
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
                    'user_id' => $actingUserId,
                ]);
            }
        }
    }
}
