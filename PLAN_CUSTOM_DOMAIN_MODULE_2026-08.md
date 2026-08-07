# Custom Domain Management — Implementation Plan (Hostinger shared hosting, no automation assumed)

Builds on the findings in `AUDIT_CUSTOM_DOMAIN_MODULE_2026-08.md`. Scoped to what you've confirmed: tenant requests → staff verifies via DNS TXT record → staff manually adds the domain as a cPanel addon domain. No code written yet — this is the plan, for approval.

## Workflow this implements

1. **Tenant** enters a domain in Settings → app generates a unique verification token and shows the tenant exactly what DNS TXT record to add at their registrar.
2. **Staff** (super admin), once the tenant says they've added it, clicks **Verify DNS** — the app does a live `dns_get_record()` lookup for that TXT record and only proceeds if the token matches. No trust-the-typed-string step anymore (closes the Critical gap from the audit).
3. Staff manually adds the domain as a cPanel addon/parked domain pointing at the same document root — **outside the app, by hand, as you specified**. Nothing in this plan automates that step; the app's job is to make it easy to know *when* that step is needed and *that* DNS is genuinely verified before staff does it.
4. Staff clicks **Activate** to confirm the manual cPanel step is done. Only at this point does the app start actually routing that domain to the tenant.
5. SSL/HTTPS: out of scope for automation on shared hosting (no cPanel API access assumed). If Hostinger's AutoSSL is enabled on the account, it typically picks up a newly-added addon domain on its own — this plan just notes that to staff, it doesn't build anything for it.

## Why step 4/routing has to be part of this, even though you didn't list it

The audit found that today, even a fully "approved" custom domain does **nothing** — `ResolveTenant::resolveByPath()` (the code path that actually runs in production) never checks `custom_domain` at all, only the unused subdomain-mode branch does. If I only build steps 1–3 as described, the workflow would end with a domain marked "active" that still 404s for real visitors, which recreates the exact problem the audit flagged. So this plan includes the minimum routing change needed to make an activated domain actually resolve to the right tenant while staying in `TENANCY_MODE=path` — without switching the whole app to subdomain mode, and without touching how `/shop/{slug}` routing works for every other tenant.

## Milestones (each its own reviewable commit, stop-and-wait after each)

**M1 — Schema** (`database/sql/chunk20.sql`, new file per this project's raw-SQL convention, not a migration)
- `custom_domain_verification_token` VARCHAR(64) — generated on request, cleared on approval/rejection.
- `custom_domain_dns_verified_at` TIMESTAMP NULL — set when the TXT check passes.
- Widen `custom_domain_request_status` ENUM to add `dns_verified` between `pending` and `approved` (mirrors how `orders.channel` was widened in `chunk13.sql`).
- No changes to any existing column, no data migration needed (all new tenants start NULL/none, exactly like today).

**M2 — `DomainManager` + `ShareDriver`** (`app/Services/Domain/`, mirroring the existing `CourierManager` pattern already used successfully in this codebase)
- `DomainDriver` interface: `verifyDns(Tenant $tenant): bool` and `activationInstructions(Tenant $tenant): string`.
- `ShareDriver implements DomainDriver`: `verifyDns()` does the real `dns_get_record($domain, DNS_TXT)` lookup and token match; `activationInstructions()` returns the "add this as a cPanel addon domain pointing at the existing docroot" text for staff, since that step can't be automated here.
- `DomainManager::driver()` returns the configured driver (config `domains.driver`, defaulting to `share` — same shape as `TENANCY_MODE`), so swapping in a future `NginxDriver`/`CaddyDriver` later touches config only, not the Settings UI or super-admin UI.
- No behavior changes to any existing controller yet in this milestone — pure new service class, safe to commit in isolation.

**M3 — Tenant Settings UI** (`SettingController::requestDomain`, `resources/views/tenant/settings.blade.php`)
- On request, generate+store the token, keep everything else about the request flow as-is (plan gating via `allow_custom_domain` unchanged).
- Show the current state clearly: pending → "add this TXT record: ..." / dns_verified → "DNS confirmed, waiting on our team to finish setup" / active → today's existing "🌐 সক্রিয় কাস্টম ডোমেইন" success state / rejected → existing rejection message. Built entirely with the existing `<x-ui.card>`/`<x-ui.badge>` components, no new visual patterns, no inline styles.

**M4 — Super Admin UI** (`SuperAdmin\TenantController`, `resources/views/super/tenant-show.blade.php`)
- Replace the current single blind "Approve" button with two explicit actions: **Verify DNS** (calls `ShareDriver::verifyDns()`, sets `dns_verified` + timestamp, shows a clear pass/fail result — "TXT record not found yet" is a normal, expected outcome given DNS propagation delays, not an error state) and **Activate** (only enabled once `dns_verified`, sets `custom_domain` + `custom_domain_verified=1` + status `approved`) — this is the step staff clicks after they've manually done the cPanel addon-domain work. **Reject** stays as-is.
- Surface `ShareDriver::activationInstructions()` text to staff right on this screen once DNS is verified, so the manual cPanel step has a clear checklist instead of tribal knowledge.

**M5 — Routing fix** (`app/Http/Middleware/ResolveTenant.php`, `routes/web.php`)
- Add a route group with a domain constraint that matches any host *except* the central domain (regex `where()` on the domain parameter — a standard Laravel technique, no package needed), reachable only in path mode, that looks up the tenant by `custom_domain` + `custom_domain_verified` (same lookup `resolveBySubdomain()` already does) and binds it — the custom domain's root becomes that tenant's storefront root.
- Existing `/shop/{slug}` routing for every tenant without a custom domain is untouched — this is strictly additive, registered so it can't shadow the existing central-domain route group.
- This is the highest-risk piece of the plan (touches shared routing infrastructure) — will be tested against a non-custom-domain tenant's existing `/shop/{slug}` URLs before and after to confirm zero regression, per your "do not break tenant routing" rule.

**M6 — Docs note only, no code**: a short note (in this plan file or CLAUDE.md, your call) that SSL is expected to come from Hostinger's AutoSSL once the addon domain is added, and isn't something this app manages.

## Explicitly not doing (unless you say otherwise)

- No cPanel/WHM API integration — you confirmed manual is fine and I'm not assuming API access exists.
- No `NginxDriver`/`CaddyDriver` yet — the `DomainDriver` interface from M2 leaves room for them later without touching DB/UI again, but building them now would be speculative given you said shared hosting must work first.
- No periodic DNS re-verification (audit flagged this as a Low item) — can add as a follow-up once the base workflow is proven.

Waiting for your approval before starting M1.
