-- Web Push notifications — foundation only (subscriptions, durable
-- notification log, per-category preferences). New, additive tables —
-- nothing existing is touched. See the mobile audit's Part C/E for the
-- reasoning: MetaSoft is a PWA, not a native app, so this is standard Web
-- Push (VAPID) rather than an FCM device-token shape.
--
-- push_subscriptions: one row per browser/device a user has granted
-- notification permission on (a user may have several — phone, tablet,
-- desktop — all active ones receive a push). endpoint is the browser's
-- opaque push-service URL, which can be long and isn't practical to index
-- directly, so endpoint_hash (sha256 of endpoint) carries the fast
-- lookup-by-endpoint path (e.g. when a push service reports 404/410 and
-- the subscription needs to be deactivated).
--
-- Uniqueness is (tenant_id, endpoint_hash), NOT endpoint_hash alone. A
-- Push subscription is scoped to the browser's origin, not to a tenant —
-- someone who manages more than one MetaSoft shop from the same browser
-- profile will have the browser hand back the SAME endpoint when they
-- subscribe while logged into a second tenant. With a bare-endpoint_hash
-- unique key, that second tenant's INSERT (already correctly scoped to
-- its own tenant_id by BelongsToTenant, so it can't find/update the first
-- tenant's row) would collide with the first tenant's row and fail with a
-- duplicate-key error — silently leaving the second tenant with no
-- working subscription on that device, with no error surfaced anywhere
-- above the database layer. Scoping the constraint to (tenant_id,
-- endpoint_hash) lets both tenants hold their own independent row for the
-- same physical endpoint, while still preventing a genuine duplicate
-- within one tenant.
CREATE TABLE push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    platform VARCHAR(20) NOT NULL DEFAULT 'web',
    device_name VARCHAR(150) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    last_seen_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_endpoint (tenant_id, endpoint_hash),
    INDEX idx_user_active (user_id, is_active)
);

-- notifications: durable record of every push sent, independent of the
-- browser actually receiving it — gives the service worker's
-- notificationclick handler and any future in-app notification list
-- something to reference/mark-read, unlike today's session-only "seen"
-- state in NotificationController (left untouched here; migrating that
-- existing, working bell fully onto this table is a separate, later piece,
-- not part of this pass — see the implementation report).
CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(30) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(255) NOT NULL,
    url VARCHAR(255) DEFAULT NULL,
    tag VARCHAR(150) DEFAULT NULL,
    data JSON DEFAULT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, read_at),
    INDEX idx_user_tag (user_id, tag)
);

-- notification_preferences: per-user, per-category opt-out. No row for a
-- (user, category) pair means "on" — the same "absence = default" pattern
-- store_settings/ai_agent_enabled already uses (see chunk30.sql), so a
-- brand-new user needs zero seeded rows to get sensible defaults. The
-- 'security' category is deliberately never checked here — SendPush always
-- delivers security alerts regardless of any row, and the preferences
-- screen never renders a toggle for it (see NotificationPreferenceService).
CREATE TABLE notification_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(30) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_category (user_id, category)
);
