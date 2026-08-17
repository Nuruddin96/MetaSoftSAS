-- Tenant Announcement — a single, GLOBAL (not per-tenant) message Super
-- Admin can set, shown on every Tenant Dashboard immediately after the
-- header. Deliberately NOT a tenants-scoped table (no tenant_id) — one
-- announcement for the whole platform, never duplicated per tenant, per
-- the task's explicit requirement. A single row (id=1, upserted by
-- SuperAdmin\AnnouncementController) is "the" current announcement; no
-- row (or an empty message) means the dashboard renders nothing.
CREATE TABLE IF NOT EXISTS platform_announcements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
);
