-- Message coalescing/debounce for the AI Agent — Part 12/13 of the
-- Customer Sales + Care Agent upgrade. Purely additive: one new nullable
-- column (+ index) on each of the two existing AI job-tracking tables,
-- nothing else changes.
--
-- Rationale: customers frequently send one logical sentence as several
-- rapid-fire messages ("আমার" / "স্কিনে এখন" / "লালচে" / "দাগ"). Without
-- coalescing, each message independently dispatches its own AI job and
-- the AI replies once per fragment. conversation_key (the channel-
-- verified sender_psid/wa_id, mirrored onto this row at webhook time so
-- the queued job never needs a join back to messenger_messages/
-- whatsapp_messages just to find out which conversation a pending row
-- belongs to) lets App\Jobs\ProcessAiAgentMessage /
-- ProcessWhatsAppAiAgentMessage detect "a newer message for this same
-- conversation is still pending" and defer to it, so only the job for
-- the LAST message in a rapid burst actually generates and sends a
-- reply — see AiAgentMessageJob::hasNewerPending()/coalescedBatchIds()/
-- claimBatch() and the mirrored methods on AiWhatsAppMessageJob.
--
-- A tenant on a not-yet-migrated environment (this column absent) simply
-- keeps the pre-coalescing behavior — see
-- AiAgentMessageJob::conversationKeyColumnReady() and its call sites in
-- MessengerWebhookController::maybeDispatchAiAgent()/
-- WhatsAppWebhookController::maybeDispatchAiAgent() and both AI jobs.
ALTER TABLE ai_agent_message_jobs
    ADD COLUMN conversation_key VARCHAR(191) NULL AFTER messenger_message_id,
    ADD INDEX idx_conversation_pending (tenant_id, conversation_key, status, messenger_message_id);

ALTER TABLE ai_whatsapp_message_jobs
    ADD COLUMN conversation_key VARCHAR(191) NULL AFTER whatsapp_message_id,
    ADD INDEX idx_conversation_pending (tenant_id, conversation_key, status, whatsapp_message_id);
