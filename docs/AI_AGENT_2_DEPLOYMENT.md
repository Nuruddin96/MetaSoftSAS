# AI Agent 2.0 — Production Deployment Checklist

Verified against this repository's actual deploy mechanism (`deploy.sh`) and current git/working-tree state at the time of writing. **Do not treat this document as a substitute for actually checking production** — several items below are marked UNKNOWN precisely because this audit had no authorization or mechanism to query the live production database.

## How this project actually deploys (verified from `deploy.sh`)

```
git pull origin main                                   (on the server, in a separate -git clone)
composer install --no-dev --optimize-autoloader
rsync code to the live directory (excludes .env, storage/, node_modules/)
php artisan storage:link   (or a manual `ln -sfn` fallback — symlink()/exec() are disabled on this host)
php artisan optimize:clear
php artisan optimize
```

**Critically: `deploy.sh` never touches `database/sql/*.sql` at all.** SQL chunk import is a fully separate, manual step on this project (consistent with `CLAUDE.md`'s "NOT migration-driven" note) — code being deployed does **not** imply its corresponding SQL has been imported, even for already-committed chunks.

## Deployment order for AI Agent 2.0 work specifically

1. **Backup database.** No backup command is defined in this repo beyond `backup.sh` (present, not read/verified as part of this audit — inspect it yourself before relying on it). At minimum, take a manual `mysqldump` of the production database before importing any new chunk.
2. **Pull/deploy code** — via `deploy.sh` as shown above, from `origin/main` on GitHub (`https://github.com/Nuruddin96/MetaSoftSAS.git`, confirmed from `git remote -v`). **Everything currently uncommitted in this working tree (see Document 10 / Phase 20A's file list) must be committed and pushed first** — deploy.sh pulls from `origin main`, it does not copy this local working tree.
3. **Check environment variables** — see the full list under "Environment variables required" below. Specifically confirm `OPENAI_API_KEY` is set on production (it is blank in `.env.example` by design).
4. **Check AI provider configuration** — `AI_PROVIDER=openai` (only implemented provider today; confirm this matches production `.env`).
5. **Check OpenAI model** — `OPENAI_MODEL` (defaults to `gpt-5-mini` if unset — confirm this is the intended production model).
6. **Check reasoning_effort** — `OPENAI_REASONING_EFFORT` (defaults to `low` — chosen after a real production incident where `gpt-5-mini` intermittently spent its whole token budget on invisible reasoning and returned no reply; do not change this without re-testing that specific failure mode).
7. **Run config cache refresh** — `php artisan config:clear` then `php artisan config:cache` (or just `php artisan optimize`, which `deploy.sh` already runs) — required because `config('ai.*')` values are read from `env()` inside `config/ai.php`, and Laravel's config cache freezes those at cache-build time.
8. **Run migrations / SQL chunks where applicable** — this project has almost no real Laravel migrations (only the framework's own `cache`/`jobs` tables per `CLAUDE.md`); the real schema is the SQL chunks below. Import via your host's DB tool (phpMyAdmin/CLI) in numeric order.
9. **Import pending SQL chunks** — see the DEPLOYED/NOT DEPLOYED/UNKNOWN table below. At minimum, `chunk37.sql`–`chunk40.sql` need review and import before the corresponding code (already in this working tree) is deployed, since that code assumes those tables/columns/indexes exist and degrades gracefully (`tablesReady()`/`columnsReady()` guards) but won't function fully without them.
10. **Verify indexes** — specifically the four added by `chunk40.sql` (`idx_tenant_psid` on `messenger_messages`, `idx_tenant_wa_id` on `whatsapp_messages`, `idx_tenant_messenger_psid` + `idx_tenant_phone` on `orders`). Verify with `SHOW INDEX FROM messenger_messages;` etc. on production — do not assume.
11. **Verify required tables/columns** — `ai_credit_accounts`, `ai_usage_ledger`, `ai_agent_message_jobs`, `ai_whatsapp_message_jobs`, `ai_handoffs`, `ai_pending_actions`, plus `sent_by` columns on `messenger_messages`/`whatsapp_messages`. Every one of these is guarded in code by a `tablesReady()`/`sentByColumnReady()`-style check, so a missing one degrades to "feature silently unavailable" rather than an error — but confirm they exist before assuming the feature is live.
12. **Verify queue worker/cron.** This project has **no persistent `queue:work` daemon** (Hostinger shared hosting has no Supervisor/systemd) — see `routes/console.php`. AI replies are entirely dependent on the standard Laravel scheduler cron entry existing on the host: `* * * * * php artisan schedule:run`. If that single cron entry is missing or broken, **no AI reply will ever be sent**, with no obvious error anywhere except an ever-growing `pending` job backlog.
13. **Verify the Laravel scheduler** specifically drains the queue: `Schedule::command('queue:work --queue=default --stop-when-empty --max-time=50 --tries=3 --timeout=30')->everyMinute()->onOneServer()->withoutOverlapping()` (in `routes/console.php`, confirmed present). Also confirms `facebook:refresh-connections` runs daily via the same cron entry.
14. **Clear/rebuild relevant caches** — `php artisan optimize:clear && php artisan optimize` (already in `deploy.sh`); also consider `Cache::flush()` only if you suspect a stale `ai_style_examples:*` key is actively wrong (normally unnecessary — it self-invalidates on a real human reply and expires after `AI_STYLE_CACHE_MINUTES`, default 360 minutes).
15. **Run `php -l` checks** — `find app -name "*.php" -print0 | xargs -0 -n1 php -l` (or your CI equivalent) on every changed file before deploying.
16. **Run targeted tests** — `php artisan test --filter=AiAgent` (851 total tests in the suite as of this audit, ~412 of them AI-Agent-specific).
17. **Run full test suite** — `composer test` (runs `config:clear` then `php artisan test`), or `php artisan test` directly.
18. **Verify logs** — tail `storage/logs/laravel.log` after deploy for any unexpected AI-pipeline warnings (see `AI_AGENT_2_OPERATIONS.md` for exact patterns to grep for).
19. **Verify AI credit balance** — check at least one real tenant's `ai_credit_accounts.balance` (via Super Admin console) is non-zero if you expect AI replies to actually send; a tenant with 0/no balance will silently stop replying (by design, not a bug).
20. **Verify Messenger connection** — a connected, active `messenger_settings`/`facebook_pages` row for at least one test tenant, and a real webhook delivery reaching `/webhook/messenger`.
21. **Verify WhatsApp connection** — same, for `whatsapp_phone_numbers`/`whatsapp_business_accounts` and `/webhook/whatsapp`.
22. **Verify handoff** — manually trigger a handoff (send "মানুষের সাথে কথা বলতে চাই" from a test account) and confirm the AI stops replying and the conversation shows in the panel as needing a human; confirm "Resume AI" actually resumes it.
23. **Verify duplicate protection** — resend the same webhook payload (or trigger Meta's own retry by returning non-200 once) and confirm no duplicate message row/reply/credit charge results.

## SQL chunk deployment status

**Do not assume any chunk is deployed to production SQL just because it's committed to git** — `deploy.sh` deploys *code* only; SQL import is a fully separate manual step this audit has no visibility into. The table below distinguishes what's actually knowable from the repository (git commit status) from what can only be confirmed by inspecting the live database directly.

| Chunk | Git status (this repo) | Production SQL import status | Notes |
|---|---|---|---|
| `chunk2.sql`–`chunk36.sql` + `schema.sql` + `extra.sql` | **Committed** (in git history) | **UNKNOWN** — committed code is a prerequisite for deployment, not proof the SQL was imported. Not independently verified against a live database in this audit. | Underpin already-tested, already-working features (credit system, handoff, WhatsApp AI, etc.) per the existing passing test suite — strongly suggests these were imported at some point, but that is an inference, not direct verification. |
| `chunk37.sql` (AiAudioTranscriptionService support / voice) | **Untracked** — not committed | **NOT DEPLOYED** (high confidence) | Cannot have gone through `deploy.sh` (`git pull`) without first being committed and pushed. |
| `chunk38.sql` (`ai_handoffs`, Phase 13) | **Untracked** — not committed | **NOT DEPLOYED** (high confidence) | Same reasoning. |
| `chunk39.sql` (Phase 14 platform pause columns) | **Untracked** — not committed | **NOT DEPLOYED** (high confidence) | Same reasoning. |
| `chunk40.sql` (Phase 17 performance indexes) | **Untracked** — not committed | **NOT DEPLOYED** (high confidence) | Same reasoning. Purely additive (`ALTER TABLE ... ADD INDEX`) — safe to import at any time once committed, but has not been. |

**Action required before any production deploy of this work:** commit `chunk37.sql`–`chunk40.sql` (and every other currently-uncommitted AI Agent file), push to `origin main`, deploy code via `deploy.sh`, **then** manually import `chunk37.sql` through `chunk40.sql` in numeric order against production, verifying each with a `DESCRIBE`/`SHOW INDEX` check before moving to the next. Do this only with your explicit go-ahead — no commit/push/deploy/import was performed as part of this documentation pass.

## Environment variables required

From `.env.example` and `config/ai.php`, AI-Agent-relevant:

| Variable | Default if unset | Purpose |
|---|---|---|
| `AI_PROVIDER` | `openai` | Which `AiProviderInterface` implementation is bound |
| `OPENAI_API_KEY` | *(blank — must be set)* | OpenAI auth; AI silently disabled without it |
| `OPENAI_MODEL` | `gpt-5-mini` | Chat model (falls back to legacy `OPENAI_AI_AGENT_MODEL` if that's set instead) |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | API base URL |
| `OPENAI_TIMEOUT_SECONDS` | `20` | Per-call HTTP timeout |
| `OPENAI_MAX_TOKENS` | `500` | `max_completion_tokens` sent to OpenAI |
| `OPENAI_REASONING_EFFORT` | `low` | Only sent for `gpt-5*`/`o1*`/`o3*`/`o4*` models |
| `OPENAI_TRANSCRIPTION_MODEL` | `whisper-1` | Voice transcription model |
| `AI_CREDIT_PER_1K_TOKENS` | `1.0` | Tenant-facing credit rate |
| `AI_CREDIT_PER_MINUTE_TRANSCRIPTION` | `0.5` | Tenant-facing transcription rate |
| `AI_AGENT_CONTEXT_MESSAGES` | `10` | History depth replayed per reply |
| `AI_STYLE_EXAMPLES_MAX` | `6` | Max style-example pairs |
| `AI_STYLE_EXAMPLE_MAX_CHARS` | `150` | Per-example trim length |
| `AI_STYLE_CACHE_MINUTES` | `360` | Style-profile cache TTL |
| `AI_PRODUCT_MATCH_SCAN_LIMIT` | `200` | Product-name scan cap |
| `AI_PRODUCT_MATCH_MAX` | `3` | Max matched products per reply |
| `AI_HUMAN_DELAY_MIN_SECONDS` / `AI_HUMAN_DELAY_MAX_SECONDS` | `2` / `6` | Humanization delay bounds |
| `AI_PENDING_ACTION_TTL_MINUTES` | `15` | Staff-panel mutating-action confirmation window |
| `AI_CHAT_MAX_TOOL_ITERATIONS` | `5` | Staff-panel tool-call loop cap |
| `FB_MESSENGER_VERIFY_TOKEN`, `WHATSAPP_VERIFY_TOKEN` | *(blank — must be set)* | Webhook verification (not AI-specific but required for either channel to receive messages at all) |

Also required, not AI-specific but load-bearing for the whole flow: `DB_QUEUE_RETRY_AFTER` (defaults `90`, used directly by `AiAgentMessageJob::claim()`'s stale-reclaim window — see Document 1 §24/§23).
