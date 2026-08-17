-- AI mutating tools + confirmation system (Phase 5). New, additive table
-- only — nothing existing is touched.
--
-- ai_pending_actions is BOTH the confirmation mechanism and the mutation
-- action log (req. #24/#25 of the AI Agent spec) — one row per mutating
-- tool call the AI ever proposed, whether or not it was ultimately
-- confirmed. Never UPDATE-overwrites the original proposal (tool_name/
-- resolved_args/summary) — only status/result/error/confirmed_at
-- transition, so the full lifecycle of every proposed mutation stays
-- reconstructable from this table alone.
--
-- The AI-mediated path (App\Services\AI\AiChatService) never mutates
-- data directly: a mutating tool's preview() (see AiMutatingTool) is
-- called instead of handle(), producing a resolved_args snapshot + a
-- human-readable summary, both stored here with status='pending'. Only
-- Tenant\AiChatController::confirm() — a real user click, a separate
-- authenticated request — ever calls the tool's actual handle(), and
-- only with the resolved_args stored here (never anything freshly
-- supplied by that confirm request), which is what makes the confirm
-- endpoint itself impossible to use to execute an arbitrary action.
CREATE TABLE ai_pending_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED DEFAULT NULL,
    tool_name VARCHAR(100) NOT NULL,
    resolved_args JSON NOT NULL,
    summary TEXT NOT NULL,
    status ENUM('pending','confirmed','rejected','expired','failed') NOT NULL DEFAULT 'pending',
    result JSON DEFAULT NULL,
    error VARCHAR(255) DEFAULT NULL,
    expires_at TIMESTAMP NOT NULL,
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE SET NULL,
    INDEX idx_tenant_status (tenant_id, status)
);

-- Links a chat bubble to the pending action it proposed, so the panel
-- chat UI knows which message to render Confirm/Reject buttons under.
-- NULL for every ordinary message (the overwhelming majority) — see
-- chunk33.sql for the base table, added there before this concept
-- existed, hence the ALTER here rather than editing that CREATE TABLE.
ALTER TABLE ai_conversation_messages
    ADD COLUMN pending_action_id BIGINT UNSIGNED DEFAULT NULL AFTER content,
    ADD FOREIGN KEY (pending_action_id) REFERENCES ai_pending_actions(id) ON DELETE SET NULL;
