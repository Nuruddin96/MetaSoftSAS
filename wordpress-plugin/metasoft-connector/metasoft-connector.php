<?php
/**
 * Plugin Name:       MetaSoft Connector
 * Description:       Securely connects this WordPress site to a MetaSoftSAS store so products, orders, customers, stock and tracking can be managed from the MetaSoftSAS panel.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MetaSoftSAS
 * License:           GPL-2.0-or-later
 * Text Domain:       metasoft-connector
 *
 * Phase 2 of the WordPress integration plan (see
 * docs/wordpress-integration-architecture.md in the MetaSoftSAS repo):
 * connection handshake + health/disconnect endpoints only. Business logic
 * (product/order/stock/tracking sync) stays in MetaSoftSAS itself — this
 * plugin is deliberately kept a thin, secure bridge, not a second copy of
 * MetaSoftSAS's business logic. Later phases extend the REST routes
 * registered in includes/class-rest-controller.php without changing the
 * connection/auth model established here.
 */

if (! defined('ABSPATH')) {
    exit; // No direct access.
}

define('METASOFT_CONNECTOR_VERSION', '0.1.0');
define('METASOFT_CONNECTOR_FILE', __FILE__);
define('METASOFT_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once METASOFT_CONNECTOR_DIR.'includes/class-connection.php';
require_once METASOFT_CONNECTOR_DIR.'includes/class-admin-page.php';
require_once METASOFT_CONNECTOR_DIR.'includes/class-rest-controller.php';

/**
 * Single point of truth for plugin bootstrapping — every hook registration
 * lives here so activation/deactivation and the two feature classes never
 * register the same hook twice.
 */
final class MetaSoft_Connector
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'load_textdomain']);

        (new MetaSoft_Connector_Admin_Page)->register();
        (new MetaSoft_Connector_REST_Controller)->register();
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('metasoft-connector', false, dirname(plugin_basename(METASOFT_CONNECTOR_FILE)).'/languages');
    }
}

add_action('plugins_loaded', ['MetaSoft_Connector', 'instance']);

register_deactivation_hook(__FILE__, function () {
    // Deliberately does NOT clear stored credentials on deactivation —
    // only an explicit "Disconnect" click (or MetaSoftSAS disconnecting
    // its side) should invalidate the connection, same as this codebase's
    // "preserve history, allow reconnect later" posture on the MetaSoftSAS
    // side (FacebookConnectController::disconnect()'s docblock). A
    // deactivate/reactivate cycle (e.g. a hosting migration) must not
    // silently sever an otherwise-healthy connection.
});
