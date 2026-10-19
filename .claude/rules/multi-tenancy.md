---
paths:
  - "app/**/*.php"
  - "routes/**/*.php"
  - "database/sql/**"
  - "tests/**/*.php"
---

# Multi-tenancy and auth checks

Architecture is described in `CLAUDE.md` (single database, `currentTenant`
bound by `ResolveTenant`, `BelongsToTenant` global scope). When a change touches
tenant data:

- Every new tenant-owned model uses `App\Traits\BelongsToTenant` and its table
  has an indexed `tenant_id`. Central models (Tenant, Plan, SuperAdmin,
  Affiliate, Client*, brand-platform models) do not.
- Never bypass the scope (`withoutGlobalScopes`, raw `DB::table` on tenant
  tables, queue jobs) without re-applying an explicit `tenant_id` filter. Jobs,
  console commands and webhooks have no request tenant: bind or filter it
  explicitly (see `BindTenantFromSanctumUser`, `bind.tenant.wp`, the Messenger and
  WhatsApp webhooks).
- Route-model binding on tenant routes must not resolve another tenant's record
  (`tests/Feature/Security/CrossTenantRouteBindingTest.php` is the pattern).
- Use the guard of the route group: `tenant`, `super_admin`, `affiliate`,
  `brand_owner`; mobile API uses Sanctum abilities + `bind.tenant.token`.
- New tenant-facing features get a cross-tenant test: tenant B cannot read,
  list, update or delete tenant A's record (see
  `tests/Feature/AiAgent/TenantIsolationAuditTest.php`).
- Subscription-exempt routes must be added to `CheckSubscription::$allowed`.
