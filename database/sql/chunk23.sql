-- Facebook OAuth "Connect Facebook" flow (Phase 1, additive).
--
-- Does NOT touch messenger_settings — the existing manually-pasted Page
-- Access Token flow keeps working exactly as before. This is a parallel
-- path: MessengerWebhookController now checks facebook_pages first and
-- falls back to messenger_settings, so both can be live at once during
-- migration. See PROJECT_KNOWLEDGE.md "Facebook OAuth" section.

-- Single-use, short-lived CSRF state for the OAuth dialog. The OAuth
-- callback is one fixed central URL (Meta does not support tenant-
-- prefixed redirect URIs), so tenant/user identity on the way back cannot
-- come from the URL or from resolve.tenant middleware — this table is the
-- sole source of truth for which tenant/user started a connect attempt.
CREATE TABLE facebook_oauth_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state CHAR(64) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'messenger',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
);

-- One Facebook user identity connected per tenant. Holds only the
-- long-lived user token (~60 days) — the short-lived token from the
-- initial code exchange is never persisted (see FacebookOAuthService).
-- granted_scopes is stored for audit only, purely additive if a future
-- Meta Ads integration needs to reuse this same connection.
CREATE TABLE facebook_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    connected_by_user_id BIGINT UNSIGNED NOT NULL,
    facebook_user_id VARCHAR(64) NOT NULL,
    user_access_token TEXT NOT NULL,
    token_expires_at TIMESTAMP NULL DEFAULT NULL,
    granted_scopes VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (connected_by_user_id) REFERENCES users(id)
);

-- OAuth-connected Facebook Pages. Deliberately NOT unique on tenant_id
-- (unlike messenger_settings) — this is what actually allows multiple
-- Pages per tenant. page_id stays UNIQUE across the whole table, the same
-- cross-tenant-hijack protection messenger_settings got in chunk21.sql.
-- status distinguishes the tenant-panel UI states (active / subscription
-- failed at connect time / needs_reconnect after a Graph call reports an
-- invalid token) without overloading the timestamp columns for that.
CREATE TABLE facebook_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    facebook_connection_id BIGINT UNSIGNED NOT NULL,
    page_id VARCHAR(50) NOT NULL UNIQUE,
    page_name VARCHAR(150) DEFAULT NULL,
    page_access_token TEXT DEFAULT NULL,
    status ENUM('active','subscription_failed','needs_reconnect') NOT NULL DEFAULT 'active',
    is_active TINYINT(1) DEFAULT 1,
    subscribed_at TIMESTAMP NULL DEFAULT NULL,
    disconnected_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (facebook_connection_id) REFERENCES facebook_connections(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);

-- Lets an incoming message record which OAuth-connected Page it arrived on,
-- now that a tenant can have more than one. NULL for messages resolved via
-- the untouched legacy messenger_settings path.
ALTER TABLE messenger_messages ADD COLUMN facebook_page_id BIGINT UNSIGNED DEFAULT NULL AFTER tenant_id;
ALTER TABLE messenger_messages ADD CONSTRAINT fk_mm_facebook_page FOREIGN KEY (facebook_page_id) REFERENCES facebook_pages(id) ON DELETE SET NULL;
