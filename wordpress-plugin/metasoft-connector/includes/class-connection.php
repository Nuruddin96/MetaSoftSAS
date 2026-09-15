<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the wp_options rows this plugin stores. Kept as its
 * own class (rather than scattering get_option()/update_option() calls
 * across the admin page and REST controller) so both call sites agree on
 * option names and on what "connected" means.
 */
class MetaSoft_Connector_Connection
{
    /** Overridable via the METASOFT_BASE_URL constant (wp-config.php) for staging — defaults to production. */
    public static function base_url(): string
    {
        if (defined('METASOFT_BASE_URL')) {
            return rtrim(METASOFT_BASE_URL, '/');
        }

        return 'https://metasoftbd.com';
    }

    public static function is_connected(): bool
    {
        return (bool) get_option('metasoft_connector_api_token');
    }

    public static function api_token(): string
    {
        return (string) get_option('metasoft_connector_api_token', '');
    }

    public static function outbound_secret(): string
    {
        return (string) get_option('metasoft_connector_outbound_secret', '');
    }

    public static function tenant_name(): string
    {
        return (string) get_option('metasoft_connector_tenant_name', '');
    }

    public static function save(string $apiToken, string $outboundSecret, string $tenantName): void
    {
        update_option('metasoft_connector_api_token', $apiToken);
        update_option('metasoft_connector_outbound_secret', $outboundSecret);
        update_option('metasoft_connector_tenant_name', $tenantName);
        update_option('metasoft_connector_connected_at', time());
    }

    public static function clear(): void
    {
        delete_option('metasoft_connector_api_token');
        delete_option('metasoft_connector_outbound_secret');
        delete_option('metasoft_connector_tenant_name');
        delete_option('metasoft_connector_connected_at');
    }

    /**
     * Sent as-is in the handshake request body and used by
     * MetaSoft_Connector_REST_Controller::health() — a single source for
     * "what does this site look like" so both call sites can't drift.
     */
    public static function site_snapshot(): array
    {
        $woocommerceActive = in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins', [])), true);

        return [
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'wp_rest_url' => rest_url(),
            'plugin_version' => METASOFT_CONNECTOR_VERSION,
            'wp_version' => get_bloginfo('version'),
            'woocommerce_active' => $woocommerceActive,
            'woocommerce_version' => $woocommerceActive && defined('WC_VERSION') ? WC_VERSION : null,
        ];
    }
}
