<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Settings → MetaSoft Connector. The only screen a tenant ever touches on
 * the WordPress side of the connection — everything else (products,
 * orders, tracking) is meant to be managed from the MetaSoftSAS panel
 * instead, per the integration's core UX goal.
 */
class MetaSoft_Connector_Admin_Page
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_metasoft_connector_connect', [$this, 'handle_connect']);
        add_action('admin_post_metasoft_connector_disconnect', [$this, 'handle_disconnect']);
    }

    public function add_menu(): void
    {
        add_options_page(
            'MetaSoft Connector',
            'MetaSoft Connector',
            'manage_options',
            'metasoft-connector',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $connected = MetaSoft_Connector_Connection::is_connected();
        ?>
        <div class="wrap">
            <h1>MetaSoft Connector</h1>

            <?php if (isset($_GET['metasoft_error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($this->error_message(sanitize_text_field(wp_unslash($_GET['metasoft_error'])))); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['metasoft_notice']) && $_GET['metasoft_notice'] === 'disconnected'): ?>
                <div class="notice notice-success"><p>ডিসকানেক্ট করা হয়েছে।</p></div>
            <?php endif; ?>

            <?php if ($connected): ?>
                <div class="notice notice-success">
                    <p>
                        <strong>সংযুক্ত আছে</strong> — MetaSoftSAS: <?php echo esc_html(MetaSoft_Connector_Connection::tenant_name()); ?>
                    </p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('metasoft_connector_disconnect'); ?>
                    <input type="hidden" name="action" value="metasoft_connector_disconnect">
                    <?php submit_button('Disconnect', 'delete'); ?>
                </form>
            <?php else: ?>
                <p>MetaSoftSAS প্যানেলের <strong>ওয়েবসাইট → WordPress কানেক্ট</strong> পেজ থেকে একটি কানেকশন কী তৈরি করে নিচে পেস্ট করুন।</p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('metasoft_connector_connect'); ?>
                    <input type="hidden" name="action" value="metasoft_connector_connect">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="connection_token">Connection Key</label></th>
                            <td><input type="text" id="connection_token" name="connection_token" class="regular-text" required></td>
                        </tr>
                        <?php if (defined('METASOFT_BASE_URL')): ?>
                        <tr>
                            <th scope="row">MetaSoftSAS URL</th>
                            <td><code><?php echo esc_html(MetaSoft_Connector_Connection::base_url()); ?></code></td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php submit_button('Connect to MetaSoftSAS'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_connect(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer('metasoft_connector_connect');

        $token = isset($_POST['connection_token']) ? sanitize_text_field(wp_unslash($_POST['connection_token'])) : '';

        if ($token === '') {
            $this->redirect_with_error('empty_token');
        }

        $payload = array_merge(
            ['connection_token' => $token],
            MetaSoft_Connector_Connection::site_snapshot()
        );

        $response = wp_remote_post(MetaSoft_Connector_Connection::base_url().'/api/wordpress/v1/handshake', [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->redirect_with_error('connection_failed');
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['connected']) || empty($body['api_token'])) {
            $this->redirect_with_error('handshake_rejected');
        }

        MetaSoft_Connector_Connection::save(
            $body['api_token'],
            $body['outbound_secret'] ?? '',
            $body['tenant_name'] ?? ''
        );

        wp_safe_redirect(admin_url('options-general.php?page=metasoft-connector'));
        exit;
    }

    public function handle_disconnect(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Forbidden', 403);
        }

        check_admin_referer('metasoft_connector_disconnect');

        MetaSoft_Connector_Connection::clear();

        wp_safe_redirect(admin_url('options-general.php?page=metasoft-connector&metasoft_notice=disconnected'));
        exit;
    }

    private function redirect_with_error(string $reason): void
    {
        wp_safe_redirect(admin_url('options-general.php?page=metasoft-connector&metasoft_error='.$reason));
        exit;
    }

    private function error_message(string $reason): string
    {
        // switch, not match() — this file targets PHP 7.4 (see the plugin
        // header's "Requires PHP"), match() is 8.0+.
        switch ($reason) {
            case 'empty_token':
                return 'Connection Key দিতে হবে।';
            case 'connection_failed':
                return 'MetaSoftSAS-এর সাথে সংযোগ করা যায়নি — একটু পর আবার চেষ্টা করুন।';
            case 'handshake_rejected':
                return 'এই Connection Key সঠিক নয় অথবা মেয়াদ শেষ হয়ে গেছে।';
            default:
                return 'একটি সমস্যা হয়েছে।';
        }
    }
}
