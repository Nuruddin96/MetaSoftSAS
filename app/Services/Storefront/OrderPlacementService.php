<?php

namespace App\Services\Storefront;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\DeliveryChargeService;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Order creation, extracted from CheckoutController::place() so both the
 * cart-based checkout page and the single-product landing page checkout
 * (Phase 2) create orders through the exact same stock-locking, customer
 * upsert, and total calculation instead of two copies drifting apart.
 */
class OrderPlacementService
{
    public function __construct(protected DeliveryChargeService $deliveryCharge) {}

    /**
     * @param  Collection<int, array{variant: ProductVariant, qty: int}>  $lines
     * @param  array{customer_name:string, customer_phone:string, customer_address:string, division_id:int, district_id:int, note?:?string}  $customerData
     *
     * @throws \RuntimeException message "insufficient_stock:{product name}" when stock ran out under lock
     */
    public function place(Collection $lines, array $customerData, string $source = 'web'): Order
    {
        $deliveryCharge = $this->deliveryCharge->calculate((int) $customerData['division_id']);

        return DB::transaction(function () use ($lines, $customerData, $deliveryCharge, $source) {
            // Re-verify stock at the actual moment of decrementing it, row-
            // locked so two simultaneous checkouts for the last unit can't
            // both succeed — the buy button already shows stock at
            // selection time, but that can go stale by order time.
            $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

            if ($warehouse) {
                foreach ($lines as $line) {
                    $available = Inventory::where('variant_id', $line['variant']->id)
                        ->where('warehouse_id', $warehouse->id)
                        ->lockForUpdate()
                        ->sum('quantity');

                    if ($available < $line['qty']) {
                        throw new \RuntimeException("insufficient_stock:{$line['variant']->product->name}");
                    }
                }
            }

            $customer = Customer::firstOrCreate(
                ['phone' => $customerData['customer_phone']],
                [
                    'name' => $customerData['customer_name'],
                    'address' => $customerData['customer_address'],
                    'division_id' => $customerData['division_id'],
                    'district_id' => $customerData['district_id'],
                ]
            );

            $subtotal = $lines->sum(fn ($l) => $l['variant']->selling_price * $l['qty']);

            $order = Order::create([
                'source' => $source,
                'customer_id' => $customer->id,
                'customer_name' => $customerData['customer_name'],
                'customer_phone' => $customerData['customer_phone'],
                'customer_address' => $customerData['customer_address'],
                'division_id' => $customerData['division_id'],
                'district_id' => $customerData['district_id'],
                'subtotal' => $subtotal,
                'delivery_charge' => $deliveryCharge,
                'total' => $subtotal + $deliveryCharge,
                'payment_method' => 'cod',
                'status' => 'pending',
                'note' => $customerData['note'] ?? null,
                'fb_event_id' => (string) Str::uuid(),
            ]);

            foreach ($lines as $line) {
                $v = $line['variant'];
                $qty = $line['qty'];

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
    }

    /** Server-side Purchase event (deduplicated with the browser pixel via fb_event_id). Never throws — an ad-tracking failure must never break checkout. */
    public function sendCapiPurchase(Order $order, Request $request): void
    {
        $mk = MarketingSetting::first();

        if (! $mk || ! $mk->fb_pixel_id || ! $mk->fb_capi_token) {
            return;
        }

        try {
            $order->load('items');

            // Test Event Code only ever leaves the server when the tenant has
            // explicitly switched Test Mode on — otherwise a leftover test
            // code must never tag a real production Purchase.
            $testEventCode = $mk->capi_test_mode ? $mk->fb_test_event_code : null;

            $result = (new MetaCapiService($mk->fb_pixel_id, $mk->fb_capi_token, $testEventCode))
                ->sendPurchase(
                    $order,
                    $request->ip(),
                    $request->userAgent(),
                    $request->cookie('_fbp'),
                    $request->cookie('_fbc'),
                );

            $mk->forceFill([
                'capi_last_status' => $result['success'] ? 'success' : 'failed',
                'capi_last_http_status' => $result['http_status'],
                'capi_last_error' => $result['success'] ? null : $result['error_message'],
                'capi_last_event_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
