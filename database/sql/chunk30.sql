-- AI Customer Support Agent — Phase 1/2 (tenant toggle + async Messenger
-- text replies). New, additive table only — nothing existing is touched.
--
-- The ON/OFF toggle itself does NOT get a new table: it reuses the
-- existing generic store_settings(tenant_id, key, value) table, the same
-- way delivery_charge_inside_dhaka/delivery_charge_outside_dhaka already
-- do (see SettingController::store()). Key: 'ai_agent_enabled',
-- value: '1'/'0'. No row for a tenant = disabled (the correct default —
-- Tenant::booted()'s auto-provisioning hook does not seed this key, so
-- no existing or newly-created tenant gets it until they explicitly
-- toggle it on in Settings).
--
-- ai_agent_message_jobs: idempotency/tracking record for the queued AI
-- reply job (ProcessAiAgentMessage). One row per inbound Messenger text
-- message that AI processing was dispatched for. The webhook inserts a
-- 'pending' row synchronously (cheap single insert, same cost class as
-- the existing mid-dedup check in MessengerWebhookController) right
-- before dispatching the job; the job can only move pending -> processing
-- via a conditional UPDATE ... WHERE status='pending' — if that affects
-- 0 rows (already claimed by a prior attempt, or the row was never
-- created), the job stops without sending anything. This is the
-- duplicate-reply guard — deliberately not relying on the queue itself.
CREATE TABLE ai_agent_message_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    messenger_message_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    UNIQUE KEY uq_messenger_message (messenger_message_id),
    INDEX idx_tenant_status (tenant_id, status)
);
