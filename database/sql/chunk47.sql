-- Cloudflare "Custom Hostnames for SaaS" domain connection tracking —
-- additive only, three new nullable/defaulted columns on tenants, nothing
-- else changes. Deliberately separate from the existing
-- custom_domain_request_status (Pending/Approved/Rejected — the admin's
-- yes/no decision on the request itself, unchanged) — this tracks the
-- TECHNICAL Cloudflare connection sub-state underneath an approved
-- request. See App\Services\Domain\CloudflareDomainService and
-- SuperAdmin\TenantController::connectDomain()/refreshDomainConnection().
--
-- custom_domain_connect_status: not_connected (never attempted) |
-- dns_required (Cloudflare not configured, or DCV/DNS not yet satisfied —
-- manual instructions shown) | connecting (Cloudflare processing
-- DNS-control-validation + SSL issuance) | connected (Cloudflare's edge
-- reports the hostname+SSL fully active) | failed (Cloudflare API
-- rejected the request, e.g. Custom Hostnames not enabled on this zone,
-- or the domain is already connected elsewhere).
--
-- "connected" is Cloudflare's edge state ONLY — it does NOT by itself
-- flip custom_domain_verified (the column ResolveCustomDomain middleware
-- actually routes production traffic on). That still requires this app's
-- own self-verification HTTP check to actually succeed first — see
-- connectDomain()'s docblock for exactly why (this Hostinger account's
-- origin server rejects any Host header without a registered vhost,
-- independent of Cloudflare, confirmed by direct testing).
ALTER TABLE tenants
    ADD COLUMN custom_domain_connect_status ENUM('not_connected','dns_required','connecting','connected','failed') DEFAULT 'not_connected' AFTER custom_domain_dns_verified_at,
    ADD COLUMN cf_custom_hostname_id VARCHAR(64) DEFAULT NULL AFTER custom_domain_connect_status,
    ADD COLUMN custom_domain_connect_error VARCHAR(255) DEFAULT NULL AFTER cf_custom_hostname_id;
