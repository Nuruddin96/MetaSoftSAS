-- "Teach Your AI Agent" — tenant-authored Q&A business-knowledge memory.
-- Additive only: one new table, nothing else changes.
--
-- Distinct from ai_custom_instructions (store_settings key, free-text
-- behavior/policy rules read by AiAgentService::systemPrompt()'s
-- $tenantInstructions section) — this is a growing list of discrete Q&A
-- pairs a tenant adds one at a time from the Settings page ("What is the
-- delivery charge inside Dhaka?" / "60 BDT inside Dhaka.") that the AI
-- matches the customer's actual question against, rather than one long
-- paragraph the model has to search through itself. See
-- App\Services\AI\AiTenantMemoryService for the matching logic — cheap,
-- deterministic keyword overlap, same "simple reliable architecture, no
-- extra OpenAI call" design as AiProductKnowledgeService.
--
-- Saving a Q&A never calls OpenAI (see Tenant\AiMemoryController) — pure
-- DB read/write, so it never touches ai_credit_accounts/ai_usage_ledger.
CREATE TABLE IF NOT EXISTS tenant_ai_memories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer TEXT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);
