-- WhatsApp AI Auto Reply (mirrors the Messenger AI Agent from chunk30.sql).
-- New, additive table only — nothing existing is touched.
--
-- No new store_settings key needs a schema change: whatsapp_ai_auto_reply_enabled
-- reuses the same generic store_settings(tenant_id, key, value) table
-- ai_agent_enabled/messenger_ai_auto_reply_enabled already use — see
-- Tenant\SettingController::aiAgent() for the write side.
--
-- ai_whatsapp_message_jobs: idempotency/tracking record for the queued
-- WhatsApp AI reply job (App\Jobs\ProcessWhatsAppAiAgentMessage) — the
-- WhatsApp counterpart of ai_agent_message_jobs (chunk30.sql), kept as a
-- SEPARATE table rather than extending that one. Two independent dedup
-- layers exist for Messenger already: the UNIQUE(mid) constraint on
-- messenger_messages stops a redelivered webhook from creating a second
-- message row, and this job-claim table separately stops a redelivered
-- webhook OR a Laravel-level job retry from sending a second AI reply for
-- a message row that already exists. whatsapp_messages already has the
-- exact same first layer (UNIQUE(wamid), chunk26.sql) — this table is the
-- second layer, mirrored one-for-one. Reusing ai_agent_message_jobs itself
-- would require making messenger_message_id nullable and adding a second,
-- mutually-exclusive whatsapp_message_id column with conditional logic
-- throughout App\Jobs\ProcessAiAgentMessage/App\Models\AiAgentMessageJob —
-- schema and logic changes to a table the already-proven, live Messenger
-- AI pipeline depends on, for no real benefit over an additive new table.
--
-- Same "webhook writes a 'pending' row synchronously right before
-- dispatching the job; the job can only proceed by winning a conditional
-- UPDATE ... WHERE status = 'pending'" design as the Messenger table — see
-- App\Models\AiWhatsAppMessageJob::claim().
CREATE TABLE ai_whatsapp_message_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    whatsapp_message_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_whatsapp_message (whatsapp_message_id),
    INDEX idx_tenant_status (tenant_id, status)
);
