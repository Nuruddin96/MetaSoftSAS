-- "Connect WordPress" integration (Phase 2 of the WordPress integration
-- plan — see docs/wordpress-integration-architecture.md for the full
-- phased roadmap). Additive, touches no existing table.
--
-- Unlike Facebook/WhatsApp (Meta OAuth against a fixed, centrally
-- registered app), a WordPress site is arbitrary and self-hosted, so there
-- is no OAuth dialog to redirect a browser to. Instead the tenant installs
-- the MetaSoft Connector plugin on their own site and pastes a one-time
-- Connection Key generated here; the plugin calls back to the central
-- /api/wordpress/v1/handshake route with that key. Once verified, ongoing
-- plugin->MetaSoftSAS API access is granted via a normal Sanctum personal
-- access token (personal_access_tokens, tokenable = a wordpress_connections
-- row) — the exact same auth mechanism the mobile app already uses
-- (Api\Mobile\AuthController::login()), not a new bespoke credential store.

-- One connected WordPress site per tenant (UNIQUE(tenant_id) — same
-- "single row, updateOrCreate" shape as facebook_connections).
CREATE TABLE wordpress_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    connected_by_user_id BIGINT UNSIGNED NOT NULL,
    site_url VARCHAR(255) NOT NULL,
    site_name VARCHAR(150) DEFAULT NULL,
    wp_rest_url VARCHAR(255) DEFAULT NULL,
    plugin_version VARCHAR(20) DEFAULT NULL,
    wp_version VARCHAR(20) DEFAULT NULL,
    woocommerce_active TINYINT(1) NOT NULL DEFAULT 0,
    woocommerce_version VARCHAR(20) DEFAULT NULL,
    -- Shared secret MetaSoftSAS uses to HMAC-sign outbound calls TO the
    -- plugin (product/stock push, future phases) — the mirror-image of the
    -- Sanctum personal access token the plugin uses to call INTO
    -- MetaSoftSAS. Generated once at handshake, given to the plugin in the
    -- same handshake response, never sent again afterward.
    outbound_secret TEXT DEFAULT NULL,
    status ENUM('pending','connected','needs_reconnect','disconnected') NOT NULL DEFAULT 'pending',
    connected_at TIMESTAMP NULL DEFAULT NULL,
    last_verified_at TIMESTAMP NULL DEFAULT NULL,
    disconnected_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (connected_by_user_id) REFERENCES users(id)
);

-- Single-use, short-lived Connection Key shown to the tenant to paste into
-- the WordPress plugin's settings screen. Plays the same role
-- facebook_oauth_states/whatsapp_oauth_states play: the sole source of
-- tenant/user identity for a request that arrives at a central,
-- non-tenant-prefixed API route, before any resolve.tenant-style context
-- can exist.
CREATE TABLE wordpress_connection_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
);
