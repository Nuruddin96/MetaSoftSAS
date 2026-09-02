<?php

namespace App\Http\Controllers\Api\WordPress;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Services\Storefront\OrderPlacementService;
use App\Services\WordPress\WordPressOrderSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 5 of the WordPress integration plan — receives a WooCommerce order
 * (created or status-changed) from the MetaSoft Connector plugin
 * (includes/class-order-sync.php's `woocommerce_checkout_order_processed`
 * / `woocommerce_order_status_changed` hooks) and feeds it into the
 * existing order pipeline (Storefront\OrderPlacementService::
 * placeExternal()) rather than a second, parallel one.
 *
 * Auth: auth:sanctum + bind.tenant.wp (routes/api.php), the exact same
 * stack `ping` already uses — tenant is ALWAYS resolved from the
 * authenticated WordPressConnection (bind.tenant.wp aborts before this
 * class ever runs if that connection isn't status=connected), never from
 * anything in the request body. Every query below relies on that already-
 * bound app('currentTenant') via each tenant-scoped model's
 * BelongsToTenant global scope — no withoutGlobalScopes() needed here,
 * unlike a route-model-bound single record (see BelongsToTenant's own
 * docblock on why that specific case differs).
 */
class WordPressOrderController extends Controller
{
    /**
     * One endpoint handles both order creation AND every subsequent
     * status-changed webhook — idempotent on `wc_order_id`
     * (orders.wordpress_order_id, UNIQUE(tenant_id, wordpress_order_id),
     * chunk60.sql) rather than two separate routes, so a retried or
     * out-of-order delivery of either webhook converges to the same
     * result instead of racing two code paths against each other.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'wc_order_id' => 'required|integer|min:1',
            'status' => 'required|string|max:30',
            'payment_method' => 'nullable|string|max:50',
            'customer.name' => 'required|string|max:150',
            'customer.phone' => 'required|string|max:20',
            'customer.address' => 'nullable|string',
            'totals.discount_total' => 'nullable|numeric',
            'totals.shipping_total' => 'nullable|numeric',
            'totals.fee_total' => 'nullable|numeric',
            'note' => 'nullable|string',
            'line_items' => 'required|array|min:1',
            'line_items.*.metasoft_variant_id' => 'required|integer|min:1',
            'line_items.*.quantity' => 'required|integer|min:1',
        ]);

        $tenant = app('currentTenant');
        $metaStatus = WordPressOrderSyncService::mapWooStatusToMetaSoft($data['status']);

        $existing = Order::where('wordpress_order_id', $data['wc_order_id'])->first();

        if ($existing) {
            return $this->applyStatusUpdate($existing, $metaStatus);
        }

        $variantIds = collect($data['line_items'])->pluck('metasoft_variant_id')->unique()->values()->all();

        // Reused from ProductVariant/Product — Phase 4 already stamped
        // every synced variant with its MetaSoftSAS id as WordPress
        // postmeta, which the plugin echoes back here as
        // metasoft_variant_id. A line item missing that mapping (a
        // product created directly in WooCommerce, never synced FROM
        // MetaSoftSAS) has nothing to resolve against — fail the whole
        // order rather than guess or silently drop the line, per this
        // phase's "fail safely, do not create a corrupt order" rule.
        $variants = ProductVariant::with('product')->whereIn('id', $variantIds)->get()->keyBy('id');

        if ($variants->count() !== count($variantIds)) {
            Log::warning('WordPress order webhook: one or more line items could not be resolved to a MetaSoftSAS product/variant.', [
                'tenant_id' => $tenant->id,
                'wc_order_id' => $data['wc_order_id'],
                'requested_variant_ids' => $variantIds,
                'resolved_variant_ids' => $variants->keys()->all(),
            ]);

            return response()->json([
                'message' => 'এক বা একাধিক প্রোডাক্ট MetaSoftSAS-এ পাওয়া যায়নি — অর্ডার তৈরি করা যায়নি।',
                'reason' => 'unresolvable_product',
            ], 422);
        }

        $lines = collect($data['line_items'])->map(fn (array $item) => [
            'variant' => $variants[$item['metasoft_variant_id']],
            'qty' => (int) $item['quantity'],
        ]);

        // Every non-per-item amount WooCommerce reports is a bounded,
        // clamped INPUT, never authoritative — see OrderPlacementService::
        // placeExternal()'s docblock. Per-item pricing is never taken from
        // here at all; placeExternal() sums $lines' live
        // ProductVariant::selling_price itself.
        $discount = max(0.0, (float) ($data['totals']['discount_total'] ?? 0));
        $deliveryCharge = max(0.0, (float) ($data['totals']['shipping_total'] ?? 0));
        $additionalAmount = max(0.0, (float) ($data['totals']['fee_total'] ?? 0));

        try {
            $order = app(OrderPlacementService::class)->placeExternal($lines, [
                'customer_name' => $data['customer']['name'],
                'customer_phone' => $data['customer']['phone'],
                'customer_address' => $data['customer']['address'] ?? null,
                'division_id' => null,
                'district_id' => null,
                'discount' => $discount,
                'additional_amount' => $additionalAmount,
                'delivery_charge' => $deliveryCharge,
                'payment_method' => $this->mapPaymentMethod($data['payment_method'] ?? null),
                'status' => $metaStatus,
                'source' => 'wordpress',
                'channel' => 'wordpress',
                'wordpress_order_id' => $data['wc_order_id'],
                'note' => $data['note'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return $this->rejectionResponse($e, $tenant->id, $data['wc_order_id']);
        } catch (QueryException $e) {
            // Race: the exact same wc_order_id arrived twice concurrently
            // (a genuine WooCommerce webhook retry racing this request) —
            // UNIQUE(tenant_id, wordpress_order_id) is the real guarantee,
            // same "check-then-insert, constraint wins the race" pattern
            // as FacebookConnectController::connect().
            if ($this->isUniqueConstraintViolation($e)) {
                $existing = Order::where('wordpress_order_id', $data['wc_order_id'])->first();

                if ($existing) {
                    return $this->applyStatusUpdate($existing, $metaStatus);
                }
            }

            throw $e;
        }

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
        ], 201);
    }

    /**
     * Status-only path for an order that already exists — never touches
     * items/stock again (that only ever happens once, at creation). A
     * no-op write when the mapped status already matches what's stored:
     * this is what keeps a MetaSoftSAS -> WooCommerce status push (see
     * WordPressOrderSyncService::pushStatusUpdate()) from turning into a
     * webhook loop if WooCommerce echoes it straight back — the echo maps
     * to the same MetaSoft status and nothing changes on this end.
     */
    protected function applyStatusUpdate(Order $order, string $metaStatus)
    {
        if ($order->status !== $metaStatus) {
            $order->update([
                'status' => $metaStatus,
                'confirmed_at' => $metaStatus === 'confirmed' && ! $order->confirmed_at ? now() : $order->confirmed_at,
                'delivered_at' => $metaStatus === 'delivered' && ! $order->delivered_at ? now() : $order->delivered_at,
            ]);
        }

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
        ], 200);
    }

    protected function rejectionResponse(\RuntimeException $e, int $tenantId, int $wcOrderId)
    {
        [$reason, $productName] = array_pad(explode(':', $e->getMessage(), 2), 2, null);

        Log::warning('WordPress order webhook: order rejected by the stock/variant pipeline.', [
            'tenant_id' => $tenantId,
            'wc_order_id' => $wcOrderId,
            'reason' => $reason,
        ]);

        $message = $reason === 'insufficient_stock'
            ? "পর্যাপ্ত স্টক নেই: {$productName}"
            : "প্রোডাক্টটি বর্তমানে নিষ্ক্রিয়: {$productName}";

        return response()->json(['message' => $message, 'reason' => $reason], 409);
    }

    /**
     * orders.payment_method is a strict ENUM('cod','bkash','nagad',
     * 'sslcommerz','cash','due') — an unrecognized WooCommerce gateway
     * slug (bacs, cheque, a payment plugin's own id, ...) must never be
     * inserted verbatim, so this maps by substring against the handful of
     * gateway families this ENUM actually distinguishes and falls back to
     * 'cod' (this integration's overwhelmingly common real-world case)
     * for everything else — never throws, since an unmapped payment
     * method is not a reason to fail the whole order.
     */
    protected function mapPaymentMethod(?string $wcMethod): string
    {
        $method = strtolower($wcMethod ?? '');

        return match (true) {
            Str::contains($method, 'bkash') => 'bkash',
            Str::contains($method, 'nagad') => 'nagad',
            Str::contains($method, 'ssl') => 'sslcommerz',
            default => 'cod',
        };
    }

    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
