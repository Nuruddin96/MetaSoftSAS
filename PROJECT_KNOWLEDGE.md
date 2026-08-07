# PROJECT_KNOWLEDGE.md

Permanent knowledge base for ShopSaaS, built from a full-codebase read-only audit (no code was changed while compiling this). **Consult this file before suggesting or writing any code.** It records not just what the code does, but *why* certain things look the way they do, and which parts are known-fragile.

This is a living document — when you learn something new about the project (a fixed bug, a changed convention, a resolved issue below), update this file rather than letting it drift out of sync with reality.

---

## 1. What this project is

ShopSaaS — a multi-tenant e-commerce + business automation SaaS for Bangladeshi merchants, Laravel 12.64.0 / PHP ^8.2. Single tenant gets: storefront, admin panel (`/panel`), POS, inventory, courier integration, fraud checking, website builder, Messenger inbox, due-ledger (বাকি খাতা). Platform also runs: central marketing site, super-admin console, affiliate program, agency "Product Source" (dropship-style catalog tenants can resell), and an owner-only Telegram AI assistant. Bengali strings are pervasive in user-facing text — preserve them when editing nearby code.

Production `.env` observed: `APP_ENV=local`, `APP_DEBUG=false`, `DB_CONNECTION=mysql`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `TENANCY_MODE=path`, `SESSION_DOMAIN=.metasoftbd.com`.

---

## 2. Architecture pattern

**Classic MVC, fat controllers, thin everything else.** No Repository pattern, no Form Requests, no CQRS/DDD layering, no API layer (`routes/api.php` does not exist). Deliberately absent from this codebase — confirmed by full search, not just "not found yet":

- **Events/Listeners** — none exist.
- **Jobs/Queues** — no `App\Jobs\*` class exists and `dispatch()` is never called, despite `QUEUE_CONNECTION=database` being configured and `queue:listen` running in `composer dev`. Queue infra is wired but unused.
- **Policies/Gates** — none exist. No `Gate::define`, no `->can()`, no `@can` in views.
- **Helpers** — no `app/Helpers` folder, no global helper file.
- Only one custom trait: `App\Traits\BelongsToTenant`.

Service classes exist only for **external integrations** (`app/Services/`): `Courier/CourierManager` + `SteadfastService`/`PathaoService`, `Payment/SslCommerzService`+`BkashService` (platform subscription billing, NOT storefront checkout), `Marketing/MetaCapiService`, `Messenger/MessengerApi`, `Assistant/AssistantBrain`+`BusinessBriefing`+`TelegramService`, `FraudChecker`. All business logic (order creation, inventory decrement, due-ledger math) lives directly in controllers.

---

## 3. Multi-tenancy — the core mechanism

Everything hinges on `app()->instance('currentTenant', $tenant)` bound by `App\Http\Middleware\ResolveTenant` (alias `resolve.tenant`).

**Two modes**, switched by `config('app.tenancy_mode')` / env `TENANCY_MODE` (**currently `path`**):
- `path`: `metasoftbd.com/shop/{tenant_slug}/...`. The `tenant_slug` route param resolves to a `Tenant`, gets `forgetParameter()`-ed off the route, and is pushed into `URL::defaults()` — controllers and `route()` calls never see it.
- `subdomain`: `{slug}.metasoftbd.com` or a verified `custom_domain`. Uses a per-tenant session cookie `sess_{tenant->id}` since multiple tenants share one central session scope. Not active in this environment but the code path is live and should keep working if `TENANCY_MODE` is flipped.

**Data isolation**: `App\Traits\BelongsToTenant` — a global Eloquent scope (`WHERE tenant_id = currentTenant.id`) plus auto-fill of `tenant_id` on `creating`. Applied to **24 of 39 models**. The other 15 are legitimately central (`Tenant`, `Plan`, `SuperAdmin`, `Affiliate`, `AffiliateCommission`, `Client`, `ClientPayment`, `ServiceLead`, `SourceProduct`, `SourceProductImage`, `SourceOrder`, `Subscription`, `SubscriptionPayment`, `AssistantMessage`) **except `SupportTicket`, which is a bug** — see §7.1.

**Subscription gating**: `CheckSubscription` (`check.subscription`) middleware runs after `resolve.tenant`. Expired/suspended tenants: storefront shows 503 closed page; panel is locked to routes named in `CheckSubscription::$allowed` (currently `tenant.billing`, `tenant.billing.pay`, `tenant.billing.callback`, `tenant.logout`) — **add any new "must work even when expired" route here explicitly.**

---

## 4. Database — NOT migration-driven (critical to know before touching schema)

`database/migrations/` only has Laravel's default `cache` and `jobs` tables. **The real schema is raw SQL in `database/sql/`:**

```
schema.sql          — base schema (33 tables), MySQL 8.0/MariaDB 10.6+ syntax
chunk2.sql … chunk19.sql, chunk2_data.sql, extra.sql
                     — sequential ALTER TABLE / INSERT patches layered on top
```

**The actual current schema = `schema.sql` + every chunk file applied in order.** There is no single file that represents "today's schema" and no automated tracking of which chunks have been applied where. Confirmed drift examples found during this audit:
- `orders.channel` — not in `schema.sql`, added by `chunk7.sql`, ENUM widened by `chunk13.sql`.
- `tenants.custom_domain_requested` / `custom_domain_request_status` — added by `chunk12.sql`.
- `tenants.referred_by_affiliate_id` — added by `chunk9.sql`.
- `incomplete_orders.session_key` — added by `chunk2.sql`.

**When making a schema change: add a new `chunkN.sql` (or edit as directed), never write a Laravel migration for app tables, and never assume `schema.sql` alone is authoritative — grep all chunk files for the table name first.**

### Table map (33 tables)

**Central / landlord (no `tenant_id`):** `plans`, `tenants`, `subscriptions`, `subscription_payments`, `super_admins`, `support_tickets`*, `support_ticket_replies`, `backup_logs` (unused, see §7.3), `bd_divisions`/`bd_districts`/`bd_upazilas` (shared BD location reference data).

*`support_tickets` has a `tenant_id` FK in schema but the model doesn't scope it — see §7.1.

**Tenant-scoped:** `users`, `warehouses`, `categories`, `products`, `product_images`, `product_variants`, `inventory`, `stock_movements`, `customers`, `orders`, `order_items`, `incomplete_orders`, `due_ledger`, `expense_categories`, `expenses`, `coupons`, `courier_settings`, `fraud_check_logs`, `marketing_settings`, `payment_gateway_settings`, `sms_settings`, `sms_logs`, `store_settings`.

Most tenant tables cascade-delete via `FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE` — deleting a `Tenant` row wipes all of that tenant's data irreversibly (see §7.2, no soft-deletes anywhere in the app).

### Key relationships
```
tenants ─┬< subscriptions > plans          products ─< product_variants ─┬< inventory > warehouses
         ├< subscription_payments                                        └< stock_movements
         ├< users, warehouses, categories,
         │  customers, orders, expenses,    orders ─┬< order_items
         │  coupons, ...                            └> customers (nullable, SET NULL)
         └(1:1) marketing_settings, sms_settings
                                             customers ─< due_ledger > orders (nullable)
bd_divisions ─< bd_districts ─< bd_upazilas
```

### Money/inventory conventions
- Order numbers: `ORD-000123`, sequential per-tenant via `lockForUpdate()` on `MAX(id)` scoped to `tenant_id` (`Order::booted()`) — "race-safe enough for shared hosting," not airtight under heavy concurrency.
- `Order::profit()` = `Σ (unit_price - purchase_price) * quantity` from `OrderItem` snapshots — purchase price is captured per line item at sale time, never looked up live from the product.
- Currency BDT. On tenant creation (`Tenant::booted()`'s `created` hook), a default `Main Warehouse` and `store_settings` rows (`delivery_charge_inside_dhaka`, `delivery_charge_outside_dhaka`, `currency`) are auto-provisioned. This is where to look if changing tenant onboarding defaults.
- **Inventory decrements have no stock-availability guard** anywhere (§7.6) — negative stock is possible.
- **`orders.warehouse_id` exists in schema but is never populated** by any of the three order-creation paths (§7.7).

---

## 5. Auth guards (4, no cross-guard user model)

| Guard | Model | Notes |
|---|---|---|
| `tenant` (default) | `App\Models\User` | Tenant panel staff/owner. `role` enum exists (`owner`/`manager`/`pos_operator`/`order_manager`) but **is completely unenforced** — see §7.10, this is the single highest-severity finding in the codebase. |
| `super_admin` | `App\Models\SuperAdmin` | Platform operator. |
| `affiliate` | `App\Models\Affiliate` | Affiliate program. |
| `web` | same as `tenant` | Kept only for framework/package compatibility, don't use directly. |

**None of the three login controllers throttle login attempts** (`TenantAuth\LoginController`, `SuperAdmin\AuthController`, `Affiliate\AuthController`) — no `RateLimiter`, no `ThrottlesLogins`, no captcha. Brute-force is currently possible against all three guards. See §7.12.

When adding auth-protected routes, pick the guard matching the route group (`auth:tenant`, `auth:super_admin`, `auth:affiliate`) — the bare `auth` middleware alias is not used anywhere in this app.

---

## 6. Controller/view namespacing convention

Grouped by **audience**, not by resource:
- `Http/Controllers/Tenant/*` + `resources/views/tenant/*` — the `/panel` admin panel
- `Http/Controllers/Storefront/*` + `resources/views/storefront/*` — public customer-facing shop
- `Http/Controllers/SuperAdmin/*` + `resources/views/super/*` — platform operator console
- `Http/Controllers/CentralAuth/*` + `resources/views/central/*` — central marketing site login/register
- `Http/Controllers/Affiliate/*` + `resources/views/affiliate/*` — affiliate program
- `Http/Controllers/TenantAuth/*` — tenant panel login only (separate from `Tenant/*` business controllers)

Match this grouping for anything new. Routes all live in one file, `routes/web.php` (332 lines) — there is no `routes/api.php`, no separate `admin.php`; SuperAdmin routes are nested inside `web.php` under `Route::prefix('super-admin')`.

`resources/views/tenant/chunk4-superadmin-payment.zip` is a stray leftover file sitting in the views folder, unused by any code — safe to remove whenever someone's doing cleanup, not urgent.

---

## 7. Known issues register (severity-classified)

Compiled during the read-only audit. **Nothing here has been fixed** — this is a punch list for future work, ordered roughly by severity. Re-verify each item's current status before acting (code may have moved on).

### 🔴 Critical

**7.10 — `users.role` (owner/manager/pos_operator/order_manager) is defined but never enforced.**
`User::isOwner()` exists but is called nowhere. No Policy, no Gate, no role-checking middleware. Any authenticated tenant staff member — regardless of role — can reach every `/panel/*` route: billing/payment initiation, courier/marketing API credentials in Settings, domain requests, product/order deletion. This is a privilege-escalation-shaped gap: the schema and model clearly intend role restriction, but nothing implements it.
*Files:* `app/Models/User.php`, `routes/web.php` (entire `panel` group — `auth:tenant` only checks login, not role), no Policy files exist.
*Fix direction:* role-based Policy/Gate, or a custom `role:owner,manager` middleware on sensitive route groups (settings, billing, destructive actions).

### 🟠 High

**7.2 — Hard delete cascades all tenant data, no safeguard.**
`SuperAdmin\TenantController::destroy()` calls `$tenant->delete()` directly → `ON DELETE CASCADE` wipes every order, customer, product, inventory row for that tenant, permanently. No soft-deletes anywhere in the app, no confirmation-by-typing-name gate server-side, no pre-delete export.
*Files:* `app/Http/Controllers/SuperAdmin/TenantController.php` (`destroy`), `database/sql/schema.sql` (cascade FKs).
*Fix direction:* soft-delete + typed confirmation + automatic export/backup before hard delete.

**7.4 — `SupportTicket` model doesn't use `BelongsToTenant` despite having `tenant_id`.**
Schema has `support_tickets.tenant_id ... ON DELETE CASCADE`, clearly designed as tenant-owned, but the model skips the trait. No controller currently uses this model (feature appears unfinished), so it's not exploitable yet — but it's a landmine: the first controller written against it will leak tickets across tenants unless someone remembers to scope manually.
*Files:* `app/Models/SupportTicket.php`.
*Fix direction:* add `use BelongsToTenant;` before any controller is built on top of it.

**7.6 — No stock-availability check before decrementing inventory.**
All three order-creation paths call `Inventory::where(...)->decrement('quantity', $qty)` with no prior check that stock is sufficient, and no DB-level `CHECK (quantity >= 0)`. Concurrent sales of the last unit, or a manual order placed with insufficient stock, can drive `inventory.quantity` negative.
*Files:* `app/Http/Controllers/Tenant/OrderController.php` (`store`), `app/Http/Controllers/Tenant/PosController.php` (`sell`), `app/Http/Controllers/Storefront/CheckoutController.php` (`place`).
*Fix direction:* `lockForUpdate()` + explicit availability check inside the transaction before decrementing, rollback with a friendly error if insufficient.

**7.9 — All external API calls run synchronously in the request cycle.**
Courier dispatch (including `bulkCourier`, which loops synchronous API calls per order), fraud check (`FraudChecker` hits every active courier's API in sequence), SMS, Messenger replies, bKash/SSLCommerz calls — none of it is queued, despite `QUEUE_CONNECTION=database` being configured and `queue:listen` already running in `composer dev`.
*Files:* `app/Services/Courier/*`, `app/Services/FraudChecker.php`, `app/Http/Controllers/Tenant/OrderController.php::bulkCourier`, `app/Services/Messenger/MessengerApi.php`, `app/Services/Payment/*`.
*Fix direction:* move these into `Job` classes and `dispatch()` them — infra is already there, only the Job classes and call-site changes are missing.

**7.12 — No login throttling on any of the 3 login forms.**
Confirmed absent in `TenantAuth\LoginController`, `SuperAdmin\AuthController`, and `Affiliate\AuthController` — none use `RateLimiter`, `ThrottlesLogins`, or a captcha. Brute-force against any known email is currently unmitigated.
*Files:* the three controllers above.
*Fix direction:* `throttle:5,1` middleware on login POST routes, or `RateLimiter::attempt()` keyed by email+IP.

**7.13 — Messenger webhook receiver doesn't verify Meta's request signature.**
`MessengerWebhookController::verify()` (GET) correctly checks `hub_verify_token`, but `receive()` (POST, the one that actually processes incoming messages) never validates the `X-Hub-Signature-256` header against the app secret. Since page ownership is resolved purely from the attacker-controllable `entry[].id` field in the POST body, a forged request naming a real connected `page_id` could inject fake messages into a tenant's Messenger inbox.
*Files:* `app/Http/Controllers/MessengerWebhookController.php` (`receive`), route is CSRF-exempt in `routes/web.php`.
*Fix direction:* verify `X-Hub-Signature-256` (HMAC-SHA256 of the raw body using the Meta app secret) before processing, reject on mismatch.

### 🟡 Medium

**7.1 — Schema is patch-layered SQL with no applied-state tracking.**
`schema.sql` + 9 chunk files, no record of which chunks have run against which environment. Risk of drift between local/staging/production, or partial application on a fresh server setup.
*Files:* all of `database/sql/`.
*Fix direction:* a lightweight applied-migrations log (even a flat file/table of chunk filenames + checksums), or a gradual move to Laravel migrations once schema stabilizes.

**7.5 — Order-creation logic duplicated across 3 controllers.**
Nearly identical "create order → decrement inventory → log stock movement" blocks in `OrderController::store`, `PosController::sell`, `CheckoutController::place`. A fix (like the stock-check in 7.6) has to be applied three times, and it's easy to miss one — which is arguably how 7.7 (missing `warehouse_id`) happened.
*Files:* the three controllers above.
*Fix direction:* extract to a shared `OrderService`.

**7.8 — No domain events for cross-cutting side-effects.**
Side-effects like affiliate commission crediting on subscription activation are hand-called from inside `BillingController`. Not a current problem at this scale, but will get messier as more side-effects (SMS on status change, webhooks) are added directly into controllers.
*Files:* `app/Http/Controllers/Tenant/BillingController.php`, `OrderController.php`, `PosController.php`.
*Fix direction:* introduce `Event`/`Listener` pairs once a second or third side-effect needs to hang off the same trigger.

### 🟢 Low

**7.3 — `backup_logs` table exists but nothing ever writes to it.**
No command or service populates it — implies a backup system that doesn't actually run. Compounds the risk in 7.2.
*Fix direction:* implement a scheduled backup command that logs here, or drop the table if backups are handled at the hosting/cPanel level instead.

**7.7 — `orders.warehouse_id` is resolved in every order-creation path but never persisted to the order.**
All three controllers compute `$warehouse` for the inventory decrement but never pass `warehouse_id` into `Order::create([...])`. The column stays permanently NULL, silently breaking any future per-warehouse sales reporting.
*Fix direction:* add `'warehouse_id' => $warehouse?->id` to each `Order::create()` call.

**Stray file:** `resources/views/tenant/chunk4-superadmin-payment.zip` — unused, safe to delete whenever convenient.

---

## 8. Things confirmed *good* (don't "fix" these)

- Sensitive credentials are properly `encrypted`/`encrypted:array` cast: `CourierSetting.credentials`, `MarketingSetting.{fb_capi_token,meta_app_secret,meta_access_token}`, `MessengerSetting.page_access_token`.
- Payment callbacks (`BillingController::bkashCallback`/`sslCallback`) never trust client-supplied status directly — they always re-verify server-side against the gateway (`bkash->executePayment()`/`queryPayment()`, `ssl->validate()`) before marking a payment completed.
- `BillingController::bkashConfigured()`/`sslConfigured()` guard against placeholder `.env` values (`আপনার_...` prefix) being mistaken for real credentials — a deliberate, sensible safety check.
- Eager loading is used correctly everywhere reviewed (`Product::with('variants')`, `ProductVariant::with('product')`) — no N+1 patterns found in the controllers audited.
- `APP_DEBUG=false` and `BCRYPT_ROUNDS=12` in the real `.env` — correct production posture.
- `CheckSubscription` middleware's allowed-routes escape hatch is a clean, explicit pattern — extend it rather than bypassing subscription checks ad hoc elsewhere.

---

## 9. Config reference (non-secret keys worth knowing)

- `config('app.central_domain')` — env `CENTRAL_DOMAIN`, default `metasoftbd.com`.
- `config('app.tenancy_mode')` — env `TENANCY_MODE`, `path` or `subdomain`.
- `config('payment.online_enabled')` — env `PAYMENT_ONLINE_ENABLED`; while false, tenants see WhatsApp/Call contact instead of payment buttons on billing.
- `config('messenger.verify_token')` — env `FB_MESSENGER_VERIFY_TOKEN`, must match Meta App webhook config exactly.
- `config('assistant.memory_turns')` — how many prior Telegram messages are replayed into the Groq system prompt per chat.
- `config('themes.*')` — niche storefront themes (skincare/organic_food/gadgets/fashion/jewelry/default); themes only ever touch typography/shape/layout, never a tenant's own colors/images/content. New themes must follow the existing key set (`font_heading`, `font_body`, `heading_family`, `body_family`, `radius`, `card_radius`, `btn_radius`, `header_style`, `spacing`, `swatch`).

---

## 10. Working agreements for this codebase

- No test suite exists beyond Laravel's stub tests (`tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`) — "run the tests" currently has nothing meaningful to run.
- Schema changes go into a new `database/sql/chunkN.sql`, never a Laravel migration (see §4).
- New tenant-owned models must `use BelongsToTenant;` (see the §7.4 cautionary tale).
- Follow the audience-based controller/view grouping (§6), not resource-based.
- Bengali user-facing strings are intentional — preserve them.
- Before recommending a fix for anything in §7, re-check the current state of the named file(s) — this document is a snapshot, not a live view.
