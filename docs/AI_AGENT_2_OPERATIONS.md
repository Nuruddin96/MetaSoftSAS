# AI Agent 2.0 — Operations / Monitoring Guide

Log path, table names, and command syntax below are verified against this repository (`config/logging.php`, `config/queue.php`, the actual `Log::` call sites in the AI pipeline) — not assumed.

## Where logs live

Default channel: `storage/logs/laravel.log` (`config/logging.php`'s `single` channel, unless `LOG_CHANNEL`/`LOG_STACK` has been overridden in production `.env` — check that first if these commands turn up nothing).

## What to monitor

### OpenAI failures / invalid requests / empty responses

Logged by `OpenAiProvider::chat()` as `'AI provider (openai): ...'`:

```bash
grep "AI provider (openai)" storage/logs/laravel.log
```

Three distinct sub-messages to distinguish:
- `"request failed at the transport level"` — network/DNS/timeout, not an OpenAI-side error.
- `"API returned an error response"` — includes `status`, `error_type`, `error_code` in the log context (never `error_message` on a 401 — see `AI_AGENT_2_SECURITY.md`).
- `"response contained no usable reply text or tool calls"` — the reasoning-token-exhaustion failure mode; check the logged `reasoning_tokens`/`completion_tokens`/`finish_reason` fields. If this recurs frequently, see "reasoning token issues" below before changing anything.

### Reasoning token issues

Same log line as above (`"response contained no usable reply text or tool calls"`). If `finish_reason` is consistently `"length"` with a high `reasoning_tokens` value, `config('ai.reasoning_effort')` (`OPENAI_REASONING_EFFORT`, default `low`) may need re-examination — this exact failure mode is why that setting exists and defaults to `low` today (see Document 4).

### Queue failures / failed jobs

Two separate things — do not confuse them:
1. **`ai_agent_message_jobs`/`ai_whatsapp_message_jobs` with `status='failed'`** — the normal, expected outcome of any non-exceptional early return (toggle off, no credit, handoff active, OpenAI failure, send failure). This is *not* a crash; check via:
   ```sql
   SELECT * FROM ai_agent_message_jobs WHERE status = 'failed' AND updated_at > NOW() - INTERVAL 1 HOUR;
   ```
2. **Laravel's own `failed_jobs` table** — a genuine uncaught fatal (OOM, worker killed mid-job, a bug outside the jobs' own try/catch). Both `ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage` catch every `\Throwable` internally and mark their own tracking row failed without rethrowing — so **a row landing in `failed_jobs` for one of these jobs is unusual and worth investigating directly**, not routine noise:
   ```sql
   SELECT * FROM failed_jobs WHERE payload LIKE '%ProcessAiAgentMessage%' OR payload LIKE '%ProcessWhatsAppAiAgentMessage%';
   ```

### Duplicate jobs

Should never happen (see Document 6 "duplicate webhook protection" / "race protection"). If you suspect one, cross-reference `ai_agent_message_jobs.messenger_message_id` for duplicates (there should be exactly one row per message ID — no unique constraint prevents a second *row*, but `claim()`'s atomic UPDATE prevents a second row from ever actually processing):

```sql
SELECT messenger_message_id, COUNT(*) FROM ai_agent_message_jobs GROUP BY messenger_message_id HAVING COUNT(*) > 1;
```

### Credit deductions

```sql
SELECT * FROM ai_usage_ledger WHERE tenant_id = ? ORDER BY id DESC LIMIT 50;
```
`type='usage'` rows are per-reply/per-transcription charges; `context_type` distinguishes `messenger_reply`/`whatsapp_reply`/`messenger_voice_transcription`/`whatsapp_voice_transcription`. A `usage` row should exist for every AI-sent reply — cross-reference against `messenger_messages`/`whatsapp_messages` `sent_by='ai'` rows for the same tenant/timeframe if a mismatch is suspected (see Document 5 §10 for the exact investigative query).

### Handoff activity

```sql
SELECT * FROM ai_handoffs WHERE resolved_at IS NULL;
```
Unresolved rows are conversations currently waiting on a human — this should correspond to what staff see flagged in their inbox.

### Image processing failures

Messenger: `MessengerWebhookController::rehostAttachment()` failures are silent (returns `null`, no dedicated log line at the point of failure verified in this audit — the attachment simply doesn't get a URL).
WhatsApp: `WhatsAppMediaService::fetch()` logs both failure modes explicitly:
```bash
grep "WhatsApp media: lookup failed\|WhatsApp media: download failed" storage/logs/laravel.log
```

### Audio transcription failures

```bash
grep "AI transcription" storage/logs/laravel.log
```

### Messenger send failures

```bash
grep "AI agent job: Messenger send failed" storage/logs/laravel.log
```

### WhatsApp send failures

```bash
grep "WhatsApp AI agent job: WhatsApp send failed" storage/logs/laravel.log
```

### Tenant isolation errors

There is no dedicated "isolation violation" log line, by design — the architecture prevents this at the query level rather than detecting it after the fact (see Document 6). If you ever suspect one occurred, this is a "stop and investigate the code, not the logs" situation — re-run `php artisan test --filter=TenantIsolationAudit` immediately and treat any failure there as critical.

### Unusually long AI replies / repeated clarification questions

No automated monitoring exists for this today — these are prompt-governed quality signals (see `AI_AGENT_2_TEST_MATRIX.md`'s PROMPT-GOVERNED rows), not something the code enforces or logs. The practical way to monitor this is periodically reading real conversation threads in the tenant inbox (`sent_by='ai'` rows), or asking tenants directly whether replies feel on-brand. Consider this a real gap if you need it monitored automatically — no code exists for it yet.

## Quick health check (safe read-only queries)

```sql
-- AI replies sent in the last hour, by channel
SELECT COUNT(*) FROM messenger_messages WHERE sent_by = 'ai' AND created_at > NOW() - INTERVAL 1 HOUR;
SELECT COUNT(*) FROM whatsapp_messages WHERE sent_by = 'ai' AND created_at > NOW() - INTERVAL 1 HOUR;

-- Jobs stuck in 'processing' longer than the queue's retry_after window (default 90s) — a sign the cron/worker stopped running
SELECT * FROM ai_agent_message_jobs WHERE status = 'processing' AND updated_at < NOW() - INTERVAL 5 MINUTE;
```
