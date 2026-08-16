-- Phase 14 — a super-admin-only "pause this tenant's AI Agent" control,
-- additive only. Deliberately NOT tenants.status = 'suspended'
-- (App\Http\Controllers\SuperAdmin\TenantController::suspend()) — that
-- already blocks the ENTIRE tenant (storefront closes with a 503, panel
-- locked to billing only, see App\Http\Middleware\CheckSubscription), a
-- much bigger hammer than pausing just the AI Agent while everything else
-- (storefront, orders, panel) keeps working normally.
--
-- Also deliberately NOT a store_settings row (the same table the
-- tenant's OWN ai_agent_enabled toggle already lives in, see
-- Tenant\SettingController::aiAgent()) — mixing platform-imposed state
-- into the tenant's own editable settings table would let a tenant's
-- future "reset AI settings" action accidentally clear an admin-imposed
-- pause, and would show the tenant their own toggle mysteriously "off"
-- with no attribution. This lives on the tenant's own row instead,
-- alongside status/subscription_ends_at — genuinely platform-owned data,
-- checked ALONGSIDE (never replacing) the tenant's own toggle in
-- App\Jobs\ProcessAiAgentMessage / ProcessWhatsAppAiAgentMessage and both
-- webhook dispatch gates.
ALTER TABLE tenants ADD COLUMN ai_paused_at TIMESTAMP NULL DEFAULT NULL AFTER status;
ALTER TABLE tenants ADD COLUMN ai_paused_by_super_admin_id BIGINT UNSIGNED DEFAULT NULL AFTER ai_paused_at;
ALTER TABLE tenants ADD COLUMN ai_paused_reason VARCHAR(255) DEFAULT NULL AFTER ai_paused_by_super_admin_id;
