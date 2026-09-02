<?php

namespace App\Services\WordPress;

use App\Models\Order;
use App\Services\WordPress\Concerns\PushesToWordPress;

/**
 * Phase 5 of the WordPress integration plan — the order-status half of the
 * outbound (MetaSoftSAS -> WordPress) direction, plus the single source of
 * truth for WooCommerce<->MetaSoft status mapping used by BOTH directions
 * (this class for outbound, Api\WordPress\WordPressOrderController for
 * inbound) so the two mappings can never silently drift apart.
 *
 * Order CREATION from a WordPress webhook is NOT here — that's
 * Storefront\OrderPlacementService::placeExternal(), reused rather than
 * duplicated (see that method's docblock). This class only pushes a
 * status change back out once an order already exists on both sides.
 */
class WordPressOrderSyncService
{
    use PushesToWordPress;

    /**
     * WooCommerce order status (without the "wc-" prefix) -> MetaSoft
     * order status. Used when a webhook creates or updates an order.
     * WooCommerce's 'on-hold' (e.g. awaiting a manual bank transfer) maps
     * to 'pending' rather than a dedicated status — this codebase's Order
     * status set (chunk: schema.sql) has no equivalent of "awaiting
     * payment confirmation" distinct from "not yet reviewed by staff",
     * and 'pending' is the correct staff-review queue for both.
     */
    protected const WOO_TO_METASOFT = [
        'pending' => 'pending',
        'on-hold' => 'pending',
        'processing' => 'confirmed',
        'completed' => 'delivered',
        'cancelled' => 'cancelled',
        'failed' => 'cancelled',
        'refunded' => 'returned',
    ];

    /**
     * The reverse direction — MetaSoft status -> WooCommerce status,
     * used by pushStatusUpdate() below. Not a perfect inverse of
     * WOO_TO_METASOFT (WooCommerce core has no "shipped" status without a
     * shipment-tracking plugin, so both 'processing' and 'shipped' push as
     * WooCommerce 'processing' — documented limitation, not a bug).
     */
    protected const METASOFT_TO_WOO = [
        'pending' => 'pending',
        'confirmed' => 'processing',
        'processing' => 'processing',
        'shipped' => 'processing',
        'delivered' => 'completed',
        'cancelled' => 'cancelled',
        'returned' => 'refunded',
    ];

    public static function mapWooStatusToMetaSoft(string $wooStatus): string
    {
        // wc-prefixed statuses (the raw post_status value) fall back
        // correctly once stripped — accepting either shape saves every
        // caller from having to know which one the plugin sent.
        $wooStatus = str_starts_with($wooStatus, 'wc-') ? substr($wooStatus, 3) : $wooStatus;

        return self::WOO_TO_METASOFT[$wooStatus] ?? 'pending';
    }

    public static function mapMetaSoftStatusToWoo(string $metaSoftStatus): ?string
    {
        return self::METASOFT_TO_WOO[$metaSoftStatus] ?? null;
    }

    /**
     * Pushes $order's current status to the WordPress site it originated
     * from. A silent no-op for every order that ISN'T WordPress-sourced —
     * there is nothing on the WooCommerce side to update for a
     * MetaSoftSAS-native order — so this is safe to call unconditionally
     * from every status-changing call site (see Tenant\OrderController::
     * updateStatus()/bulkStatus()).
     *
     * Deliberately NOT triggered by an inbound webhook's own status write
     * (Api\WordPress\WordPressOrderController never calls this) — pushing
     * a status back that WordPress just told us about would be a pointless
     * round trip. It IS safe even if that round trip happened anyway: the
     * plugin's status endpoint is a plain "set status to X", and
     * WordPressOrderController's own inbound handling only ever writes a
     * status when it actually differs from what's stored (see that
     * controller's docblock) — so a repeated echo converges to a no-op on
     * both ends rather than looping.
     */
    public function pushStatusUpdate(Order $order): void
    {
        if ($order->source !== 'wordpress' || ! $order->wordpress_order_id) {
            return;
        }

        $wooStatus = self::mapMetaSoftStatusToWoo($order->status);

        if (! $wooStatus) {
            return;
        }

        $connection = $this->connectionFor($order->tenant_id);

        if (! $connection) {
            return;
        }

        $this->send($connection, 'post', "orders/{$order->wordpress_order_id}/status", ['status' => $wooStatus]);
    }
}
