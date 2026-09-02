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
            $warehouse = $this->lockStockOrFail($lines);

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

            $this->attachItemsAndDecrementStock($order, $lines, $warehouse);

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });
    }

    /**
     * Phase 5 of the WordPress integration plan — an inbound order from a
     * connected WooCommerce site. Reuses this same class's stock-locking
     * (oversell protection, the whole reason this isn't just
     * Api\OrderCreationService::createFromManualEntry(), which trusts a
     * staff member already looking at current stock) and item-attach
     * logic, but differs from place() in three ways external orders
     * genuinely need:
     *
     * - customer_address/division_id/district_id are free-form/nullable —
     *   a WooCommerce billing address has no relationship to Bangladesh's
     *   division/district reference data, unlike the BD-only storefront
     *   checkout place() serves.
     * - discount/additional_amount/delivery_charge are caller-supplied
     *   (from the WooCommerce order's own discount/fee/shipping totals)
     *   rather than recalculated from DeliveryChargeService — but
     *   pricing itself never trusts the caller: subtotal is always
     *   summed from $lines' live ProductVariant::selling_price, exactly
     *   like place() and Api\OrderCreationService::calcSubtotal(), never
     *   from any WooCommerce-reported line total. See
     *   Api\WordPress\WordPressOrderController for the clamping applied
     *   to the caller-supplied amounts before they ever reach here.
     * - source/wordpress_order_id/status are caller-supplied instead of
     *   hardcoded 'web'/'pending' — status still only ever takes the
     *   value WordPressOrderSyncService::mapWooStatusToMetaSoft() already
     *   validated against a fixed status list, never an arbitrary string.
     *
     * @param  Collection<int, array{variant: ProductVariant, qty: int}>  $lines
     * @param  array{customer_name:string, customer_phone:string, customer_address:?string, division_id:?int, district_id:?int, discount:float, additional_amount:float, delivery_charge:float, payment_method:string, status:string, source:string, channel:string, wordpress_order_id:?int, note?:?string}  $orderData
     *
     * @throws \RuntimeException message "insufficient_stock:{product name}" or "inactive_variant:{product name}" when validation fails under lock
     */
    public function placeExternal(Collection $lines, array $orderData): Order
    {
        return DB::transaction(function () use ($lines, $orderData) {
            $warehouse = $this->lockStockOrFail($lines);

            $customer = Customer::firstOrCreate(
                ['phone' => $orderData['customer_phone']],
                [
                    'name' => $orderData['customer_name'],
                    'address' => $orderData['customer_address'],
                    'division_id' => $orderData['division_id'],
                    'district_id' => $orderData['district_id'],
                ]
            );

            $subtotal = $lines->sum(fn ($l) => $l['variant']->selling_price * $l['qty']);
            $discount = min((float) $orderData['discount'], $subtotal);
            $additionalAmount = (float) $orderData['additional_amount'];
            $deliveryCharge = (float) $orderData['delivery_charge'];
            $total = $subtotal - $discount + $additionalAmount + $deliveryCharge;

            $order = Order::create([
                'source' => $orderData['source'],
                'channel' => $orderData['channel'],
                'wordpress_order_id' => $orderData['wordpress_order_id'] ?? null,
                'customer_id' => $customer->id,
                'customer_name' => $orderData['customer_name'],
                'customer_phone' => $orderData['customer_phone'],
                'customer_address' => $orderData['customer_address'],
                'division_id' => $orderData['division_id'],
                'district_id' => $orderData['district_id'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'additional_amount' => $additionalAmount,
                'delivery_charge' => $deliveryCharge,
                'total' => $total,
                'payment_method' => $orderData['payment_method'],
                'status' => $orderData['status'],
                'confirmed_at' => $orderData['status'] === 'confirmed' ? now() : null,
                'delivered_at' => $orderData['status'] === 'delivered' ? now() : null,
                'note' => $orderData['note'] ?? null,
                'fb_event_id' => (string) Str::uuid(),
            ]);

            $this->attachItemsAndDecrementStock($order, $lines, $warehouse);

            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);

            return $order;
        });
    }

    /**
     * Re-verifies stock at the actual moment of decrementing it, row-
     * locked so two simultaneous orders for the last unit can't both
     * succeed — a buy button (or a WooCommerce page) already shows stock
     * at selection time, but that can go stale by order time. Shared by
     * place() and placeExternal() so oversell protection can never drift
     * between the two entry points.
     *
     * @param  Collection<int, array{variant: ProductVariant, qty: int}>  $lines
     *
     * @throws \RuntimeException message "inactive_variant:{product name}" or "insufficient_stock:{product name}"
     */
    protected function lockStockOrFail(Collection $lines): ?Warehouse
    {
        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

        foreach ($lines as $line) {
            // A deactivated variant must never be purchasable even via a
            // crafted request with its real id — the storefront UI already
            // hides it (product-buy-widget.blade.php scopes to
            // is_active=1), but that's a frontend courtesy only, same as
            // the stock check below; this is the actual enforcement.
            if (! $line['variant']->is_active) {
                throw new \RuntimeException("inactive_variant:{$line['variant']->product->name}");
            }
        }

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

        return $warehouse;
    }

    /**
     * @param  Collection<int, array{variant: ProductVariant, qty: int}>  $lines
     */
    protected function attachItemsAndDecrementStock(Order $order, Collection $lines, ?Warehouse $warehouse): void
    {
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
