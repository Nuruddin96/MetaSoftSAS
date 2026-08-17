-- AI Agent Credit/Wallet system (Phase 1 of the tenant AI Agent
-- initiative). New, additive tables only — nothing existing is touched.
-- Deliberately modeled on ad_billing_accounts/ad_billing_ledger
-- (chunk29.sql) — same "cached balance + append-only ledger" shape,
-- adapted rather than copied:
--   - no is_active/daily_budget/billing_rate columns: unlike the
--     Advertising module, an AI credit wallet has no separate
--     "module enabled" switch — a missing account row (or balance <= 0)
--     IS the "no credit" state, matching this codebase's existing
--     "no row = off" convention (ai_agent_enabled, messenger_settings).
--   - adds input_tokens/output_tokens/model/context_type/context_id,
--     which Advertising's ledger has no equivalent of, to satisfy the
--     token/usage tracking requirement.
--
-- ai_credit_accounts: one row per tenant that has ever been allocated AI
-- credit. Row is created on first allocation (see AiCreditService —
-- no separate "activate" step, unlike AdvertisingBalanceService::activate()).
-- balance is a cached snapshot, mutated only inside the same transaction
-- as the ai_usage_ledger row that justifies the change.
CREATE TABLE ai_credit_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    balance DECIMAL(12,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- ai_usage_ledger: immutable transaction ledger (append-only — no UPDATE
-- or DELETE call site is ever written against this table), same shape as
-- ad_billing_ledger (type + credit_amount + balance_after snapshot), plus:
--   input_tokens/output_tokens  actual token counts from the OpenAI
--                                response for a 'usage' row; NULL for
--                                'allocation'/'adjustment_*' rows (no API
--                                call happened).
--   model                       which model this usage row billed against
--                                — config('ai.openai_model') is per-deploy
--                                configurable (see config/ai.php), so this
--                                is recorded per-row rather than assumed.
--   estimated_cost_usd          admin-only informational estimate of the
--                                real OpenAI USD cost, computed from a
--                                configurable per-model price table
--                                (config('ai.pricing')) — NEVER used to
--                                gate or deduct credit; credit deduction
--                                uses the separate, tenant-facing
--                                config('ai.credit_per_1k_tokens') rate.
--                                Never selected by any tenant-facing
--                                controller/view — see
--                                AiUsageLedger::TENANT_VISIBLE_COLUMNS.
--   context_type/context_id     which subsystem triggered this row (e.g.
--                                'messenger_reply' + messenger_messages.id)
--                                — future-proofs for panel chat/tools
--                                without a schema change.
--   created_by                  super_admins.id for allocation/adjustment
--                                rows; NULL for system-generated usage rows.
CREATE TABLE ai_usage_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    type ENUM('allocation','usage','adjustment_credit','adjustment_debit') NOT NULL,
    credit_amount DECIMAL(12,4) NOT NULL,
    balance_after DECIMAL(12,4) NOT NULL,
    input_tokens INT UNSIGNED DEFAULT NULL,
    output_tokens INT UNSIGNED DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    estimated_cost_usd DECIMAL(10,6) DEFAULT NULL,
    context_type VARCHAR(50) DEFAULT NULL,
    context_id BIGINT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES super_admins(id) ON DELETE SET NULL,
    INDEX idx_tenant (tenant_id, created_at)
);
