<?php
/**
 * Plugin Name:       MetaSoft Connector
 * Description:       Securely connects this WordPress site to a MetaSoftSAS store so products, orders, customers, stock and tracking can be managed from the MetaSoftSAS panel.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Author:            MetaSoftSAS
 * License:           GPL-2.0-or-later
 * Text Domain:       metasoft-connector
 *
 * Phase 2 built the connection handshake + health/disconnect endpoints.
 * Phase 4 added the receiving end of MetaSoftSAS's product/category/stock
 * push (includes/class-woocommerce-sync.php). Phase 5 (this version) adds
 * the other direction: WooCommerce order create/status-change ->
 * MetaSoftSAS's existing order pipeline (includes/class-order-sync.php),
 * plus the REST route MetaSoftSAS uses to push a status change back. See
 * docs/wordpress-integration-architecture.md in the MetaSoftSAS repo for
 * the full phased plan. Business logic (pricing, stock validation,
 * customer/product matching) still stays entirely in MetaSoftSAS — this
 * plugin only reads/writes WooCommerce's own API calls from an
 * already-decided payload, never re-derives one.
 */

if (! defined('ABSPATH')) {
    exit; // No direct access.
}

define('METASOFT_CONNECTOR_VERSION', '0.3.0');
define('METASOFT_CONNECTOR_FILE', __FILE__);
define('METASOFT_CONNECTOR_DIR', plugin_dir_path(__FILE__));

require_once METASOFT_CONNECTOR_DIR.'includes/class-connection.php';
require_once METASOFT_CONNECTOR_DIR.'includes/class-admin-page.php';
require_once METASOFT_CONNECTOR_DIR.'includes/class-woocommerce-sync.php';
require_once METASOFT_CONNECTOR_DIR.'includes/class-order-sync.php';
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
        (new MetaSoft_Connector_Order_Sync)->register();
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
