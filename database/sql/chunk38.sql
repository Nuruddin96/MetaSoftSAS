-- Phase 13 — real human handoff mechanism for the AI Customer Support
-- Agent. Additive only: a new table, nothing else changes.
--
-- Until this table existed, config('ai.system_prompt') explicitly forbade
-- the AI from ever claiming a human had been notified/would follow up
-- ("I'll notify a representative", "I'll escalate this", etc.) — those
-- were promises the app had no way to keep. This table is what makes that
-- claim actually true: an unresolved row (resolved_at IS NULL) for a
-- given (tenant_id, channel, external_id) means a real person needs to
-- handle this conversation, and the AI Agent must not auto-reply to it
-- again until a tenant staff member explicitly resolves it from the
-- panel inbox (see App\Services\AI\AiHandoffService).
--
-- external_id is the Messenger psid or WhatsApp wa_id — the same
-- channel-verified identifier App\Services\AI\AiCustomerMemoryService and
-- AiCustomerEmotionService already key off, never a customer-typed value.
--
-- Deliberately its own table, not a new messenger_messages/
-- whatsapp_messages column: handoff is a CONVERSATION-level state (every
-- future message in the thread, not just one row), and the two message
-- tables have different shapes/schemas, so one small shared table
-- (queried identically by both channels) is simpler than two parallel
-- column additions plus per-row bookkeeping.
--
-- reason is a short machine-readable tag (e.g. 'customer_requested'),
-- never free text — see AiHandoffService::REASON_* constants.
CREATE TABLE IF NOT EXISTS ai_handoffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('messenger','whatsapp') NOT NULL,
    external_id VARCHAR(100) NOT NULL,
    reason VARCHAR(50) NOT NULL,
    triggered_by_message_id BIGINT UNSIGNED DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_lookup (tenant_id, channel, external_id, resolved_at)
);
