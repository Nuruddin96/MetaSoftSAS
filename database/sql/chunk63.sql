-- FCM (Firebase Cloud Messaging) device push tokens — the native-app
-- counterpart to chunk31.sql's push_subscriptions (browser Web Push). New,
-- additive table; nothing existing is touched.
--
-- Deliberately NOT the same table as mobile_devices (Remote Support): a
-- mobile_devices row only exists once a tenant has opted into Remote
-- Support (DeviceController::register() 404s otherwise, see that
-- controller's docblock) and mobile_devices.fcm_token is scoped to that
-- feature's own wake-proof-of-concept. General app push (new order/
-- message notifications) must work for every tenant regardless of Remote
-- Support, so it gets its own table registered under the ordinary
-- login-token auth group, not the device-credential one.
--
-- Uniqueness is on token alone, NOT (tenant_id, token) like
-- push_subscriptions' endpoint: an FCM token identifies one specific app
-- installation, which is logged into exactly one tenant/user at a time
-- (unlike a browser that can hold sessions for several tenants
-- simultaneously) — so registering an already-known token just reassigns
-- it to the current tenant_id/user_id (e.g. a re-login or a different
-- staff member using the same phone) rather than creating a duplicate row.
CREATE TABLE device_push_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'android',
    app_version VARCHAR(30) DEFAULT NULL,
    last_seen_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_token (token),
    INDEX idx_user_active (user_id, is_active)
);
