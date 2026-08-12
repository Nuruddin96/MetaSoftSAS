-- Advertising / Ads Billing module (tenant-facing wallet + admin billing).
-- New, additive tables only — nothing existing is touched.
--
-- ad_billing_accounts: one row per tenant that has the Advertising module
-- switched on. Row existence + is_active is the per-tenant activation
-- switch (same pattern as messenger_settings for the Messenger module).
-- `balance` is a cached snapshot of the tenant-facing balance (kept in
-- sync transactionally with ad_billing_ledger by AdvertisingBalanceService)
-- so the header chip and Overview page never need to SUM the ledger on
-- every read.
CREATE TABLE ad_billing_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    daily_budget DECIMAL(12,2) NOT NULL DEFAULT 0,
    billing_rate DECIMAL(10,4) NOT NULL DEFAULT 0,
    low_balance_threshold DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- ad_billing_ledger: immutable transaction ledger (append-only — no UPDATE
-- or DELETE call site is ever written against this table). Same shape as
-- the existing due_ledger table (type + amount + balance_after snapshot),
-- plus three Advertising-specific, admin-only columns:
--   meta_spend_usd  the actual USD Meta ad spend behind a 'charge' row.
--                   Never selected by any tenant-facing controller/view —
--                   see AdBillingLedger::TENANT_VISIBLE_COLUMNS.
--   charge_date     the Asia/Dhaka calendar date a daily charge covers.
--                   NULL for payments/manual adjustments. The UNIQUE index
--                   below (NULLs don't collide in MySQL) makes the daily
--                   charge job idempotent per tenant per day.
--   created_by      the super_admins.id who entered a manual payment/
--                   charge/adjustment; NULL for the automated daily charge.
CREATE TABLE ad_billing_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    type ENUM('payment','charge','adjustment_credit','adjustment_debit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    meta_spend_usd DECIMAL(10,2) DEFAULT NULL,
    charge_date DATE DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES super_admins(id) ON DELETE SET NULL,
    INDEX idx_tenant (tenant_id, created_at),
    UNIQUE INDEX uq_daily_charge (tenant_id, charge_date)
);

-- No ALTER TABLE plans needed — plans.allow_meta_ads already exists (part
-- of the original CREATE TABLE plans in schema.sql, DEFAULT 1, currently
-- unused anywhere in app/ or resources/). This chunk wires it into the
-- module's activation gate — see AdvertisingBalanceService::isEnabled().
