-- "AI মেমোরী" voice answers — a saved Q&A's answer may be text OR a
-- recorded/uploaded voice clip instead. Additive only: two new nullable
-- columns on the existing tenant_ai_memories table (chunk41.sql), no
-- existing data touched.
--
-- answer stays TEXT and remains required for answer_type='text' rows;
-- for answer_type='audio' rows, answer_audio_path holds the tenant-scoped
-- storage path (see Tenant\AiMemoryController — same "public" disk,
-- ai-memory/{tenant_id}/ prefix pattern the Messenger/WhatsApp outbound
-- media uploads already use) and answer may be null (or a short caption,
-- the tenant's choice) since the actual answer is the audio file itself.
--
-- When App\Services\AI\AiTenantMemoryService finds a strong match on an
-- audio-answer memory, the AI job sends that file directly via the
-- existing MessengerApi::sendAttachment()/WhatsAppSendService::sendMedia()
-- outbound-media paths instead of calling OpenAI at all — see that
-- service's bestAudioMatch() docblock.
ALTER TABLE tenant_ai_memories
    ADD COLUMN answer_type ENUM('text','audio') NOT NULL DEFAULT 'text' AFTER answer,
    ADD COLUMN answer_audio_path VARCHAR(255) DEFAULT NULL AFTER answer_type,
    MODIFY COLUMN answer TEXT NULL;
