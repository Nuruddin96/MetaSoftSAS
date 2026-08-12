-- Facebook Messenger customer identity (name + profile photo), additive.
--
-- messenger_messages.customer_name already exists and keeps working exactly
-- as before (still written on every inbound message, still a fallback data
-- source everywhere it's read) — this table is a richer, canonical identity
-- record ON TOP of that, not a replacement: adds first_name/last_name/
-- profile_pic/staleness tracking that has nowhere to live on the message
-- table without denormalizing it onto every single row.
--
-- UNIQUE(tenant_id, psid) matches the existing tenant-isolation semantic
-- already used everywhere else a PSID is queried in this codebase
-- (messenger_messages itself has no page-scoped uniqueness either) — a PSID
-- is treated as unique-per-tenant, not unique-per-Page-within-a-tenant,
-- since every existing query (resolvedNameFor, fetchMessengerCandidates,
-- etc.) already groups/matches purely by (tenant_id, sender_psid).
--
-- facebook_page_id is nullable and deliberately NOT part of the unique key
-- (informational only) since resolvePageOwner()'s legacy messenger_settings
-- fallback path never has one (see FacebookPage::tablesReady()'s docblock)
-- — this table must stay usable for tenants still on that flow too, not
-- just OAuth-connected ones.
--
-- No access-token column here on purpose — the Page access token stays
-- exactly where it already lives (facebook_pages.page_access_token,
-- encrypted) and is looked up via facebook_page_id/tenant_id, never
-- duplicated onto this table.
--
-- Import this AFTER chunk23.sql (creates facebook_pages, which this table's
-- FK references) — same ordering requirement every other chunk in this
-- directory already has.
CREATE TABLE IF NOT EXISTS messenger_customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    facebook_page_id BIGINT UNSIGNED DEFAULT NULL,
    psid VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    name VARCHAR(150) DEFAULT NULL,
    profile_pic_url VARCHAR(500) DEFAULT NULL,
    profile_pic_fetched_at TIMESTAMP NULL DEFAULT NULL,
    identity_fetched_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (facebook_page_id) REFERENCES facebook_pages(id) ON DELETE SET NULL,
    UNIQUE KEY uq_tenant_psid (tenant_id, psid),
    INDEX idx_identity_fetched (identity_fetched_at)
);
