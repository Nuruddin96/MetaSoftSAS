# AI Agent 2.0 — Rollback Plan

Every command/path below was verified against this repository. Nothing here should be run without your explicit go-ahead — this document only explains how, it does not perform any action.

## 1. How to disable AI Agent immediately (one tenant)

Super Admin console → that tenant's AI Credit page → **Pause AI** (`POST {tenant}/pause-ai`, `SuperAdmin\AiCreditController::pauseAi()`). This sets `tenants.ai_paused_at`/`ai_paused_by_super_admin_id`/`ai_paused_reason` and is checked independently of the tenant's own toggles by both `ProcessAiAgentMessage` and `ProcessWhatsAppAiAgentMessage` before every single reply (`Tenant::isAiPaused()`). Reverse with the same page's **Resume AI** button (`{tenant}/resume-ai` in `SuperAdmin\AiCreditController::resumeAi()`).

**There is no single "pause every tenant at once" button** — this is a per-tenant action. To stop AI for *every* tenant simultaneously, see §11 (stop queue processing) instead, which is the actual global kill switch.

A tenant-side equivalent (does not require Super Admin access) also exists: in that tenant's own panel Settings, turn off "AI Agent" (`store_settings` key `ai_agent_enabled`, checked by `ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage` on every message).

## 2. How to disable Messenger AI (one tenant)

Tenant panel Settings → turn off the Messenger-specific AI toggle (`store_settings` key `messenger_ai_auto_reply_enabled`). WhatsApp AI is unaffected.

## 3. How to disable WhatsApp AI (one tenant)

Same page, the WhatsApp-specific toggle (`whatsapp_ai_auto_reply_enabled`). Messenger AI is unaffected.

## 4. How to revert code

Standard git revert against `origin/main` (the branch `deploy.sh` pulls from — confirmed in `git remote -v`/`deploy.sh`). Identify the commit(s) to revert with `git log --oneline`, then `git revert <sha>` (creates a new commit undoing it — safer than `reset --hard` on a shared branch), push, and run the normal deploy (`deploy.sh` on the server, or your equivalent). Do **not** force-push or rewrite history on `main` unless you have a specific, deliberate reason and full team awareness — this repo's own safety conventions (and this session's own operating rules) treat that as a high-risk action requiring explicit authorization every time.

## 5. How to restore database changes

This project's schema is **not** migration-driven — there is no `php artisan migrate:rollback` for the SQL chunks. Reversal means manually running the inverse SQL for whichever chunk(s) you need to undo, checked against each chunk's actual `ALTER`/`CREATE` statements before writing the inverse. See §6/§7 for what's safe vs. what must never be touched this way.

## 6. Which additive schema changes are safe to reverse

Purely additive, structural-only changes with no data dependency, in principle reversible by dropping exactly what was added:
- `chunk40.sql` — four `ADD INDEX` statements only (`idx_tenant_psid` on `messenger_messages`, `idx_tenant_wa_id` on `whatsapp_messages`, `idx_tenant_messenger_psid` + `idx_tenant_phone` on `orders`). Reversible with plain `ALTER TABLE ... DROP INDEX <name>` if genuinely needed — but there is no operational reason to: extra indexes only cost write throughput/disk, they cannot break a read, and dropping them the moment after adding them would defeat the entire point of Phase 17.
- Any of `chunk37.sql`–`chunk39.sql`'s new **empty** tables/columns, if reverted **before any real data has been written to them** — check row counts first, always.

## 7. Which changes must NOT be manually deleted

Never `DROP`/`DELETE` against, and never truncate:
- **`messenger_messages` / `whatsapp_messages`** — real customer conversation history. See §8.
- **`ai_usage_ledger`** — the AI credit financial audit trail (every allocation, adjustment, and usage deduction). See §9.
- **`ai_handoffs`** — the record of when a real human was (or wasn't) actually asked for; deleting rows here could later make it impossible to prove/disprove a "the AI never told me a human was coming" support dispute.
- **`orders`** — real order data; AI Agent code reads from this table but never owns it.
- **`ai_credit_accounts`** — deleting a tenant's balance row is functionally identical to a silent, unexplained credit wipe.

If a feature genuinely needs to be fully removed later, prefer archiving (rename table, or add a `deprecated_` prefix) over `DROP TABLE`, so the data is recoverable if the decision turns out to be wrong.

## 8. How to preserve customer messages

Do nothing — they are never deleted by any AI Agent code path (every failure mode in the jobs simply doesn't create a row; nothing ever removes an existing one). If you're rolling back the *feature*, leave `messenger_messages`/`whatsapp_messages` rows with `sent_by='ai'` in place; they're a legitimate historical record of what was actually said, and removing them would corrupt the conversation thread's continuity for staff reading it later.

## 9. How to preserve the AI credit ledger

Same — do nothing; `ai_usage_ledger` is append-only from the application's own code (no update/delete path exists anywhere in `AiCreditService`). If you need to *correct* a balance, use `AiCreditService::adjust()` (Super Admin console's manual adjustment feature) to add a new, explained, audited entry — never edit or delete an existing ledger row directly in the database.

## 10. How to investigate accidental AI replies

Query `messenger_messages`/`whatsapp_messages` filtered to `sent_by = 'ai'` for the affected tenant and time range — every AI-sent reply is tagged this way, unspoofably (see `AI_AGENT_2_SECURITY.md` §"sent_by security"). Cross-reference with `ai_usage_ledger` (`context_type` = `messenger_reply`/`whatsapp_reply`/`*_voice_transcription`, `context_id` = the triggering message's ID) to see exactly what was billed for each reply, and with `ai_agent_message_jobs`/`ai_whatsapp_message_jobs` (`status`, `updated_at`) to see the job-level trace of what ran and when.

## 11. How to stop queue processing if necessary

This project has no persistent `queue:work` daemon — AI replies (and the `facebook:refresh-connections` scheduled task) only run because of a single cron entry on the host: `* * * * * php artisan schedule:run` (see `routes/console.php`). **Removing or disabling that one cron entry stops all AI Agent processing platform-wide, for every tenant, immediately** — this is the actual global kill switch, more absolute than any per-tenant pause. Re-adding the cron entry resumes processing from wherever the `pending`-status job backlog left off (nothing is lost — jobs simply wait in the `ai_agent_message_jobs`/`ai_whatsapp_message_jobs` tables and the `jobs` queue table until a worker run picks them up again).

A less drastic alternative that stops only AI-generated *sends* without touching the cron entry that other scheduled tasks (`facebook:refresh-connections`) also rely on: pause AI for every tenant via §1, tenant by tenant, from the Super Admin console.
