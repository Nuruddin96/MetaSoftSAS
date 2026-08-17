-- Phase 17 — performance. Purely additive: new composite indexes only,
-- no column or data changes, no application behavior change.
--
-- Every AI Agent hot-path query — App\Jobs\ProcessAiAgentMessage::
-- recentHistory()/resolveOutboundToken(), the WhatsApp counterparts,
-- App\Services\AI\AiConversationStyleService, AiCustomerMemoryService,
-- AiCustomerEmotionService — filters by BOTH tenant_id AND the channel
-- identifier (sender_psid / wa_id / messenger_psid / customer_phone)
-- together, on every single inbound message. This is the highest-
-- frequency code path in the entire application.
--
-- The existing indexes on these columns only ever cover tenant_id and
-- the channel identifier SEPARATELY:
--   messenger_messages: idx_tenant(tenant_id, created_at), idx_psid(sender_psid)
--   whatsapp_messages:  idx_tenant(tenant_id, created_at), idx_wa_id(wa_id)
--   orders:             idx_phone(customer_phone), idx_messenger_psid(messenger_psid)
-- MySQL has no single index that's genuinely selective for "this tenant's
-- messages from this one conversation" — at real message volume this
-- means a much larger index scan (all of one tenant's messages, or all
-- tenants' messages from one psid/phone) than the query actually needs,
-- not a true composite-key seek.
--
-- Deliberately NOT dropping the old single-column indexes here — some
-- other, non-AI-Agent code path outside this audit's scope may still
-- rely on one of them (e.g. a genuinely tenant-unscoped lookup); removing
-- an index carries its own risk that needs its own dedicated check, not
-- bundled into a performance-only change.
--
-- Safe to import at any time (adds indexes, touches no existing data),
-- though on a large existing table building these will take real time
-- and can briefly affect write throughput on some MySQL configurations —
-- run during a low-traffic window on production, same caution as any
-- index addition to a live table.
ALTER TABLE messenger_messages ADD INDEX idx_tenant_psid (tenant_id, sender_psid, id);
ALTER TABLE whatsapp_messages ADD INDEX idx_tenant_wa_id (tenant_id, wa_id, id);
ALTER TABLE orders ADD INDEX idx_tenant_messenger_psid (tenant_id, messenger_psid);
ALTER TABLE orders ADD INDEX idx_tenant_phone (tenant_id, customer_phone);
