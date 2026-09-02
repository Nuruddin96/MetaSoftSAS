<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * REST routes MetaSoftSAS calls INTO this site (the mirror-image of the
 * handshake/ping routes this site calls into MetaSoftSAS — see
 * class-admin-page.php and routes/api.php's wordpress/v1 group on the
 * MetaSoftSAS side). Deliberately minimal for this phase: a public health
 * check (used by MetaSoftSAS's "Verify Connection" button) and an
 * authenticated disconnect notice. Product/stock push endpoints are added
 * here in a later phase without touching the connection/auth model.
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
}
