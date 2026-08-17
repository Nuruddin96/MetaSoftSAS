# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## What this is

ShopSaaS — a multi-tenant e-commerce + business automation SaaS for Bangladeshi merchants, built on Laravel 12 / PHP 8.2. Each tenant gets a storefront, an admin panel (`/panel`), POS, inventory, orders, courier integration, fraud checking, a website builder, and messenger inbox. The platform also runs a central marketing site, a super-admin console, an affiliate program, and a personal Telegram AI assistant for the owner. Bengali strings appear throughout user-facing text (flash messages, the assistant's system prompt, theme labels) — preserve them when editing nearby code.

## Commands

```bash
composer dev          # runs server + queue:listen + pail (logs) + vite concurrently — primary dev command
php artisan serve      # app server only
npm run dev            # vite only
npm run build           # production asset build

composer test          # config:clear + php artisan test
php artisan test --filter=TestName
php artisan test tests/Feature/SomeTest.php

vendor/bin/pint         # code style (Laravel Pint, no custom pint.json — defaults apply)
```

There is now a real, substantial test suite (96 test files, 850+ tests as of the AI Agent 2.0 work) — `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` are leftover framework stubs, not representative of coverage. The AI Agent feature set in particular (`tests/Feature/AiAgent/`) has extensive coverage including a dedicated tenant-isolation audit (`TenantIsolationAuditTest`/`TenantIsolationAuditWhatsAppTest`) — see `docs/AI_AGENT_2_TEST_MATRIX.md` for what is and isn't covered by it.

## Database: NOT migration-driven

This is the single most important deviation from a standard Laravel app. The full schema lives as raw SQL in `database/sql/` (`schema.sql` plus numbered `chunk*.sql` files, split for shared-hosting import limits) — **not** in `database/migrations/`. The `migrations/` directory only has Laravel's default `cache` and `jobs` tables.

- Do not "fix" a schema issue by writing a new Laravel migration unless asked — check `database/sql/schema.sql` first for the authoritative table definitions.
- If a schema change is needed, it should generally be reflected in the SQL files, matching the existing style.
- `composer.json`'s `setup`/`post-create-project-cmd` scripts still call `php artisan migrate` out of Laravel boilerplate habit — for this project the real schema comes from importing the SQL files, not from that command.

## Multi-tenancy architecture

Everything hinges on `app()->instance('currentTenant', $tenant)` being bound early in the request lifecycle by `App\Http\Middleware\ResolveTenant` (aliased as `resolve.tenant` in `bootstrap/app.php`).

**Two tenancy resolution modes**, switched by `TENANCY_MODE` in `.env` (currently `path` in this environment):
- `path` (shared-hosting friendly, current mode): `metasoftbd.com` = central app, `metasoftbd.com/shop/{tenant_slug}` = tenant storefront + panel. The `tenant_slug` route parameter is resolved to a `Tenant`, then stripped from the route (`forgetParameter`) and pushed into `URL::defaults()` so controllers and `route()` calls never deal with it directly.
- `subdomain` (post-VPS-migration mode): `metasoftbd.com` = central, `{subdomain}.metasoftbd.com` or a verified custom domain = tenant. Uses a per-tenant session cookie (`sess_{tenant->id}`) since multiple tenants share the same central session scope.

Route structure in `routes/web.php` reflects this: central routes are wrapped in `Route::domain(config('app.central_domain'))`, and all tenant + storefront routes are built via a shared `$tenantRoutes` closure applied conditionally depending on `tenancy_mode`.

**Data isolation** is enforced by the `App\Traits\BelongsToTenant` trait, not by scoping code in each controller. Any model using it gets:
- a global Eloquent scope filtering every query by `tenant_id` against `app('currentTenant')`
- auto-fill of `tenant_id` on create

Apply this trait to every new tenant-owned model (see `Product`, `Order`, etc. for the pattern). Central-only models (`Tenant`, `Plan`, `SuperAdmin`, `Affiliate`, `Client*`) do not use it.

**Subscription gating**: `App\Http\Middleware\CheckSubscription` (`check.subscription`) runs after `resolve.tenant` on both panel and storefront routes. If a tenant's trial/subscription has lapsed, storefront visitors see a 503 "closed" page and panel users are locked to the billing page only (routes named in `CheckSubscription::$allowed`). Add any new "must work even when expired" route to that list explicitly.

## Auth guards

Four separate guards in `config/auth.php`, each session-based with its own Eloquent provider — there is no cross-guard user model:
- `tenant` (default) → `App\Models\User` — tenant panel staff/owner
- `super_admin` → `App\Models\SuperAdmin` — platform operator
- `affiliate` → `App\Models\Affiliate`
- `web` → kept pointing at the same `User`/`tenant_users` provider purely for framework/package compatibility

When adding auth-protected routes, pick the guard matching the route group (`auth:tenant`, `auth:super_admin`, `auth:affiliate`) — don't rely on the unqualified default outside the tenant panel.

## Controller/view namespacing convention

Controllers, and most views, are grouped by audience, not by resource:
- `Http/Controllers/Tenant/*` + `resources/views/tenant/*` — the tenant admin panel (`/panel`)
- `Http/Controllers/Storefront/*` + `resources/views/storefront/*` — public customer-facing shop
- `Http/Controllers/SuperAdmin/*` + `resources/views/super/*` — platform operator console
- `Http/Controllers/CentralAuth/*` + `resources/views/central/*` — central marketing site login/register
- `Http/Controllers/Affiliate/*` + `resources/views/affiliate/*` — affiliate program
- `Http/Controllers/TenantAuth/*` — tenant panel login (separate from `Tenant/*` business controllers)

Match this grouping when adding new controllers/views rather than introducing a resource-based structure.

## Storefront theming

`config/themes.php` defines niche-specific visual themes (skincare, organic food, gadgets, fashion, jewelry, default) — Google Fonts, border radii, header style, spacing — selectable per tenant. Themes never touch a tenant's own colors, uploaded images, banners, or product content; they only change typography/shape/layout "feel". When adding a new theme, follow the existing key structure exactly (`font_heading`, `font_body`, `heading_family`, `body_family`, `radius`, `card_radius`, `btn_radius`, `header_style`, `spacing`, `swatch`) since views consume these keys directly.

## Domain services (`app/Services/`)

- `Courier/CourierManager` — dispatches to `SteadfastService` or `PathaoService` based on `CourierSetting` rows (per-tenant credentials stored as JSON); returns `null` when required credentials are missing rather than throwing.
- `Payment/SslCommerzService`, `Payment/BkashService` — **platform-level** subscription billing gateways (tenant paying MetaSoft), not storefront checkout payment.
- `Marketing/MetaCapiService` — Meta Conversions API integration.
- `Messenger/MessengerApi` — Facebook Messenger send API, paired with the single global webhook in `routes/web.php` (`/webhook/messenger`) that looks up the owning tenant per-page inside the controller, since Meta only supports one webhook URL per app.
- `Facebook/FacebookOAuthService` — the "Connect Facebook" OAuth flow (state issuance/validation, token exchange, `/me/accounts`, per-Page webhook subscription). One central, non-tenant-prefixed callback route (`facebook.callback`) since Meta doesn't support templated redirect URIs — tenant identity flows through a signed, single-use `facebook_oauth_states` row instead. Additive alongside the original manual-token `messenger_settings` flow, which still works unchanged. See `PROJECT_KNOWLEDGE.md` §11 for the full architecture.
- `Assistant/*` — the owner-only Telegram bot (`TelegramController`, routes not tied to any tenant/domain). `AssistantBrain` calls Groq's free OpenAI-compatible chat API using a live `BusinessBriefing` snapshot injected into the system prompt, plus rolling per-chat message history (`AssistantMessage`, capped by `assistant.memory_turns`). The system prompt is Bengali and instructs the assistant it can only advise, never perform actions (e.g. suspending a tenant) directly.
- `FraudChecker` — backs `Tenant/FraudCheckController`, used to flag risky orders before confirmation.

## Money/inventory conventions

- `Order` numbers are generated as `ORD-000123`, sequential per-tenant, using `lockForUpdate()` on `MAX(id)` scoped to `tenant_id` — "race-safe enough for shared hosting," not a strict guarantee under heavy concurrency.
- `Order::profit()` computes from `OrderItem`s as `(unit_price - purchase_price) * quantity` — purchase price is captured per line item, not looked up live from the product.
- Currency is BDT; delivery charges are seeded per-tenant on creation (`Tenant::booted()`'s `created` hook) as `store_settings` rows, along with a default `Main Warehouse`. If you change tenant auto-provisioning, this is the place.
