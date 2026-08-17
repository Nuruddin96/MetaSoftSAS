-- Tenant-authenticated AI panel chat (Phase 4). New, additive tables
-- only — nothing existing is touched.
--
-- The Messenger AI Agent (chunk30.sql, Phase 1/2) has no conversation
-- table of its own — it replays recent messenger_messages rows as
-- context, which works because Messenger already stores every message.
-- The panel chat is the first AI surface with no existing message table
-- to piggyback on, so it gets a real one here.
--
-- ai_conversations: one ongoing thread per (tenant, user) — a tenant
-- staff member's single running chat with their store's AI Agent, not a
-- ChatGPT-style multi-thread sidebar (kept deliberately simple; nothing
-- in the current requirements asks for multiple named conversations per
-- user). Created lazily on that user's first message.
CREATE TABLE ai_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tenant_user (tenant_id, user_id)
);

-- ai_conversation_messages: only the user-visible turns — the user's own
-- message and the AI's final natural-language reply. Deliberately does
-- NOT persist the intermediate tool-calling round trip (the assistant's
-- tool_calls request, or the tool results fed back to it) — those are
-- rebuilt fresh from App\Services\AI\Tools\AiToolRegistry on every turn
-- rather than replayed from history, the same way a normal chat UI only
-- shows the final answer, not the tool mechanics behind it. Keeps this
-- table's shape identical to messenger_messages' role/content core
-- (see App\Jobs\ProcessAiAgentMessage::recentHistory()) rather than
-- inventing a second, more complex history-replay shape.
CREATE TABLE ai_conversation_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
    INDEX idx_conversation (conversation_id, created_at)
);
