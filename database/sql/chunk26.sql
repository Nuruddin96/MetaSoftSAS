-- WhatsApp Cloud API integration — Phase 1 (schema + models only, additive).
--
-- Mirrors the Facebook OAuth feature's table shape/conventions
-- (chunk19.sql/chunk21.sql/chunk22.sql/chunk23.sql/chunk25.sql) wherever the
-- underlying platform actually works the same way, and deliberately departs
-- from it where WhatsApp's API shape differs — see each table's own comment.
--
-- Does NOT touch messenger_settings / messenger_messages / facebook_* in any
-- way. This is a fully independent set of tables; the webhook, send service,
-- and onboarding controller that will read/write them are later phases.

-- Single-use, short-lived CSRF state for the WhatsApp Embedded Signup flow.
-- Same role as facebook_oauth_states: Embedded Signup completes via a
-- popup/JS SDK callback, not a tenant-prefixed redirect, so tenant/user
-- identity on the way back must come from this row, never from the URL or
-- resolve.tenant middleware. Not used by any code yet — schema only, ahead
-- of the onboarding controller that will consume it in a later phase.
CREATE TABLE whatsapp_oauth_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state CHAR(64) NOT NULL UNIQUE,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'whatsapp',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_expires (expires_at)
);

-- One WhatsApp Business Account connection per tenant. Holds the long-lived
-- per-tenant access token Embedded Signup returns (never a platform-wide
-- System User token — see PROJECT_KNOWLEDGE.md decision record for this
-- integration). granted_scopes stored for audit only, same as
-- facebook_connections.
--
-- UNIQUE(waba_id) in addition to UNIQUE(tenant_id): stricter than
-- facebook_connections (which only enforces one-per-tenant, because a
-- Facebook login identity isn't itself a claimable business asset — that
-- protection lives on facebook_pages.page_id instead). A WABA IS the asset
-- here, so it gets the same hijack-prevention treatment page_id/
-- phone_number_id get, one layer earlier than the Facebook pattern does.
CREATE TABLE whatsapp_business_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    connected_by_user_id BIGINT UNSIGNED NOT NULL,
    waba_id VARCHAR(64) NOT NULL UNIQUE,
    business_id VARCHAR(64) DEFAULT NULL,
    business_name VARCHAR(150) DEFAULT NULL,
    user_access_token TEXT NOT NULL,
    token_expires_at TIMESTAMP NULL DEFAULT NULL,
    granted_scopes VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (connected_by_user_id) REFERENCES users(id)
);

-- OAuth-connected WhatsApp phone numbers. Not unique on tenant_id (a WABA
-- can have multiple registered numbers) — same "one connection, many
-- claimable assets" shape as facebook_connections -> facebook_pages.
-- phone_number_id stays globally UNIQUE, the same cross-tenant-hijack
-- protection page_id has on facebook_pages, since this is the ID the
-- webhook will route incoming messages by.
--
-- Deliberately NO access_token column here (unlike facebook_pages): the
-- WhatsApp Cloud API has no per-phone-number token — sending via
-- /{phone_number_id}/messages authenticates with the WABA-level token on
-- whatsapp_business_accounts, reached via facebook_connection_id's
-- equivalent below (whatsapp_business_account_id). Adding a token column
-- here would just be a second place for the same secret to leak.
CREATE TABLE whatsapp_phone_numbers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    whatsapp_business_account_id BIGINT UNSIGNED NOT NULL,
    phone_number_id VARCHAR(64) NOT NULL UNIQUE,
    display_phone_number VARCHAR(30) DEFAULT NULL,
    verified_name VARCHAR(150) DEFAULT NULL,
    quality_rating VARCHAR(20) DEFAULT NULL,
    status ENUM('active','subscription_failed','needs_reconnect') NOT NULL DEFAULT 'active',
    is_active TINYINT(1) DEFAULT 1,
    subscribed_at TIMESTAMP NULL DEFAULT NULL,
    disconnected_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (whatsapp_business_account_id) REFERENCES whatsapp_business_accounts(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);

-- WhatsApp conversation messages. Deliberately its own table, not a shared
-- messages table with messenger_messages — the unified "All conversations"
-- inbox (a later phase) merges the two at query time instead, so neither
-- channel's existing/future logic has to reason about the other's schema.
--
-- Mirrors messenger_messages' proven shape (nullable-unique message id for
-- webhook dedup, direction, the same new/contacted/converted/ignored status
-- enum for UI parity with the existing inbox) plus what WhatsApp's payload
-- actually needs on top:
--   - message_type: WhatsApp carries non-media message shapes Messenger
--     doesn't (location/contacts/interactive/button) — needs an explicit
--     type rather than inferring one from attachment presence.
--   - delivery_status: WhatsApp's sent/delivered/read/failed lifecycle is
--     a DIFFERENT axis from the new/contacted/converted/ignored triage
--     `status` column above — kept as a separate column on purpose so a
--     later delivery-receipt webhook handler never has to touch triage
--     state, and vice versa.
--   - raw_payload: full incoming message JSON for types with no dedicated
--     column yet (location coordinates, contact vcards, interactive/button
--     replies) — lets the webhook (a later phase) store everything now
--     without a further schema change once that parsing is written.
--   - error_code/error_message: outbound send failures must be visible on
--     the row, not just logged, so the panel can show why a message never
--     went through.
CREATE TABLE whatsapp_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    whatsapp_phone_number_id BIGINT UNSIGNED DEFAULT NULL,
    wa_id VARCHAR(30) NOT NULL,
    wamid VARCHAR(100) DEFAULT NULL,
    customer_name VARCHAR(150) DEFAULT NULL,
    message_type VARCHAR(20) DEFAULT NULL,
    message_text TEXT DEFAULT NULL,
    attachment_url VARCHAR(500) DEFAULT NULL,
    attachment_type VARCHAR(20) DEFAULT NULL,
    attachment_name VARCHAR(255) DEFAULT NULL,
    raw_payload JSON DEFAULT NULL,
    direction ENUM('in','out') DEFAULT 'in',
    status ENUM('new','contacted','converted','ignored') DEFAULT 'new',
    delivery_status ENUM('sent','delivered','read','failed') DEFAULT NULL,
    error_code VARCHAR(30) DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (whatsapp_phone_number_id) REFERENCES whatsapp_phone_numbers(id) ON DELETE SET NULL,
    UNIQUE INDEX uq_wamid (wamid),
    INDEX idx_tenant (tenant_id, created_at),
    INDEX idx_wa_id (wa_id)
);
