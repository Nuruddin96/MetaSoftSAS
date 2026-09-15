<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST routes MetaSoftSAS calls INTO this site (the mirror-image of the
 * handshake/ping routes this site calls into MetaSoftSAS — see
 * class-admin-page.php and routes/api.php's wordpress/v1 group on the
 * MetaSoftSAS side). Health check stays public (proves only reachability);
 * every other route requires the outbound_secret bearer token — this
 * class stays a thin request/response wrapper, all WooCommerce upsert
 * logic lives in MetaSoft_Connector_WooCommerce_Sync (Phase 4).
 */
class MetaSoft_Connector_REST_Controller
{
    const NAMESPACE = 'metasoft/v1';

    public function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route(self::NAMESPACE, '/health', [
                'methods' => 'GET',
                'callback' => [$this, 'health'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route(self::NAMESPACE, '/disconnect', [
                'methods' => 'POST',
                'callback' => [$this, 'disconnect'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);

            register_rest_route(self::NAMESPACE, '/products', [
                'methods' => 'POST',
                'callback' => [$this, 'upsert_product'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);

            register_rest_route(self::NAMESPACE, '/products/(?P<id>\d+)', [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_product'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);

            register_rest_route(self::NAMESPACE, '/categories', [
                'methods' => 'POST',
                'callback' => [$this, 'upsert_category'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);

            register_rest_route(self::NAMESPACE, '/categories/(?P<id>\d+)', [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_category'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);

            register_rest_route(self::NAMESPACE, '/stock', [
                'methods' => 'POST',
                'callback' => [$this, 'update_stock'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);

            // Phase 5 — MetaSoftSAS pushing an order status change back to
            // this site. See WordPressOrderSyncService::pushStatusUpdate()
            // on the MetaSoftSAS side and MetaSoft_Connector_Order_Sync::
            // apply_status_from_metasoft() here.
            register_rest_route(self::NAMESPACE, '/orders/(?P<id>\d+)/status', [
                'methods' => 'POST',
                'callback' => [$this, 'update_order_status'],
                'permission_callback' => [$this, 'verify_outbound_secret'],
            ]);
        });
    }

    /**
     * Public on purpose — proves only that the plugin is installed, active
     * and reachable, never any credential. See WordPressConnectorService::
     * verify()'s docblock on the MetaSoftSAS side for what this is (and
     * isn't) used to conclude.
     */
    public function health(\WP_REST_Request $request)
    {
        return new \WP_REST_Response([
            'connected' => MetaSoft_Connector_Connection::is_connected(),
            'plugin_version' => METASOFT_CONNECTOR_VERSION,
            'site_name' => get_bloginfo('name'),
        ], 200);
    }

    /**
     * Bearer token compared against the outbound_secret this site was
     * given at handshake time (see class-admin-page.php::handle_connect())
     * — the mirror-image of the Sanctum token MetaSoftSAS gave this site
     * to authenticate the other direction. hash_equals() to stay
     * timing-attack safe, same reasoning any bearer-token comparison in
     * this codebase would use.
     */
    public function verify_outbound_secret(\WP_REST_Request $request): bool
    {
        $expected = MetaSoft_Connector_Connection::outbound_secret();

        if ($expected === '') {
            return false;
        }

        $header = $request->get_header('authorization');
        $provided = $header && stripos($header, 'Bearer ') === 0 ? substr($header, 7) : '';

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public function disconnect(\WP_REST_Request $request)
    {
        MetaSoft_Connector_Connection::clear();

        return new \WP_REST_Response(['disconnected' => true], 200);
    }

    public function upsert_product(\WP_REST_Request $request)
    {
        $result = MetaSoft_Connector_WooCommerce_Sync::upsert_product($request->get_json_params());

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    public function delete_product(\WP_REST_Request $request)
    {
        $result = MetaSoft_Connector_WooCommerce_Sync::delete_product((int) $request->get_param('id'));

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    public function upsert_category(\WP_REST_Request $request)
    {
        $result = MetaSoft_Connector_WooCommerce_Sync::upsert_category($request->get_json_params());

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    public function delete_category(\WP_REST_Request $request)
    {
        $result = MetaSoft_Connector_WooCommerce_Sync::delete_category((int) $request->get_param('id'));

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    public function update_stock(\WP_REST_Request $request)
    {
        $result = MetaSoft_Connector_WooCommerce_Sync::update_stock($request->get_json_params());

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }

    public function update_order_status(\WP_REST_Request $request)
    {
        $status = (string) $request->get_param('status');
        $orderId = (int) $request->get_param('id');

        $result = MetaSoft_Connector_Order_Sync::apply_status_from_metasoft($orderId, $status);

        return new \WP_REST_Response($result, $result['ok'] ? 200 : 422);
    }
}
