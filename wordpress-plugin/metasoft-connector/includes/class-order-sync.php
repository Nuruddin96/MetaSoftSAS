<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Phase 5 of the WordPress integration plan: the WordPress -> MetaSoftSAS
 * order direction. Hooks a new/changed WooCommerce order and POSTs it to
 * MetaSoftSAS's authenticated /api/wordpress/v1/orders endpoint using the
 * Sanctum api_token this site received at handshake time (see
 * class-admin-page.php) — the mirror-image of the outbound_secret bearer
 * MetaSoft_Connector_REST_Controller checks for calls arriving FROM
 * MetaSoftSAS.
 *
 * Every line item is matched to its MetaSoftSAS id via the
 * _metasoft_product_id / _metasoft_variant_id postmeta Phase 4's product
 * push already stamped on the WooCommerce product/variation — this file
 * never invents or guesses that mapping, only reads it back. A line item
 * with no such meta (a product created directly in WooCommerce, never
 * synced FROM MetaSoftSAS) is still sent, with a null metasoft_variant_id
 * — MetaSoftSAS's own webhook handler is what decides to reject the whole
 * order for that (see WordPressOrderController::store()'s docblock), not
 * this file, so the rejection reason stays defined in exactly one place.
 */
class MetaSoft_Connector_Order_Sync
{
    public function register(): void
    {
        // Fires once, right after a checkout creates the order — covers
        // every WooCommerce checkout flow (blocks and classic), unlike
        // 'woocommerce_new_order' which also fires for orders created
        // programmatically/by an admin and would double-send on some
        // payment gateways that call wc_create_order() more than once
        // internally before the order is actually finalized.
        add_action('woocommerce_checkout_order_processed', [$this, 'push_order'], 10, 1);

        // Covers every later status transition (payment confirmed,
        // manually marked complete, cancelled, refunded, ...), including
        // ones MetaSoftSAS itself triggered via the status-push route
        // below — see WordPressOrderController::applyStatusUpdate()'s
        // docblock on the MetaSoftSAS side for why that round trip
        // converges to a no-op instead of looping.
        add_action('woocommerce_order_status_changed', [$this, 'push_order_by_id'], 10, 1);
    }

    public function push_order_by_id(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if ($order) {
            $this->push_order($order);
        }
    }

    public function push_order($order): void
    {
        if (! MetaSoft_Connector_Connection::is_connected()) {
            return;
        }

        if (! ($order instanceof WC_Order)) {
            $order = wc_get_order($order);
        }

        if (! $order) {
            return;
        }

        $lineItems = [];
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            $metasoftVariantId = $product ? (int) $product->get_meta(MetaSoft_Connector_WooCommerce_Sync::VARIANT_ID_META) : 0;

            if (! $metasoftVariantId && $product && $product->is_type('simple')) {
                // A simple product's "variant" id was stamped on the
                // product itself, not a separate variation — see
                // class-woocommerce-sync.php::apply_simple_variant().
                $metasoftVariantId = (int) $product->get_meta(MetaSoft_Connector_WooCommerce_Sync::VARIANT_ID_META);
            }

            $lineItems[] = [
                'metasoft_variant_id' => $metasoftVariantId ?: null,
                'sku' => $item->get_product() ? $item->get_product()->get_sku() : null,
                'quantity' => (int) $item->get_quantity(),
            ];
        }

        $payload = [
            'wc_order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'payment_method' => $order->get_payment_method(),
            'customer' => [
                'name' => trim($order->get_billing_first_name().' '.$order->get_billing_last_name()) ?: $order->get_formatted_billing_full_name(),
                'phone' => $order->get_billing_phone(),
                'address' => trim(implode(', ', array_filter([
                    $order->get_billing_address_1(),
                    $order->get_billing_address_2(),
                    $order->get_billing_city(),
                    $order->get_billing_state(),
                ]))),
            ],
            'totals' => [
                'discount_total' => (float) $order->get_discount_total(),
                'shipping_total' => (float) $order->get_shipping_total(),
                'fee_total' => (float) $order->get_total_fees(),
            ],
            'note' => $order->get_customer_note(),
            'line_items' => $lineItems,
        ];

        $this->send($payload);
    }

    /**
     * The receiving end of WordPressOrderSyncService::pushStatusUpdate()
     * on the MetaSoftSAS side — $status here has ALREADY been mapped to a
     * WooCommerce status (WordPressOrderSyncService::
     * mapMetaSoftStatusToWoo()), this method just applies it. Calling
     * update_status() re-fires 'woocommerce_order_status_changed', which
     * push_order_by_id() above is hooked to — that round trip is expected
     * and safe, see WordPressOrderController::applyStatusUpdate()'s
     * docblock on the MetaSoftSAS side for why it converges to a no-op
     * rather than looping.
     *
     * @return array{ok: bool, message?: string}
     */
    public static function apply_status_from_metasoft(int $orderId, string $status): array
    {
        if (! class_exists('WooCommerce')) {
            return ['ok' => false, 'message' => 'WooCommerce is not active on this site.'];
        }

        $order = wc_get_order($orderId);

        if (! $order) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        $validStatuses = array_keys(wc_get_order_statuses());
        if (! in_array('wc-'.$status, $validStatuses, true)) {
            return ['ok' => false, 'message' => 'Unknown WooCommerce status: '.$status];
        }

        $order->update_status($status, 'Updated from MetaSoftSAS.');

        return ['ok' => true];
    }

    protected function send(array $payload): void
    {
        $response = wp_remote_post(MetaSoft_Connector_Connection::base_url().'/api/wordpress/v1/orders', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.MetaSoft_Connector_Connection::api_token(),
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('[MetaSoft Connector] Order push failed: '.$response->get_error_message());

            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            // Deliberately not logging the response body — it may echo
            // back customer PII (name/phone/address) submitted in the
            // request. The status code plus the order id is enough to
            // find and re-drive this from the MetaSoftSAS side if needed.
            error_log(sprintf('[MetaSoft Connector] Order push rejected (HTTP %d) for wc_order_id=%d', $code, $payload['wc_order_id']));
        }
    }
}
