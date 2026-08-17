# AI Agent 2.0 — Architecture

Generated from the actual codebase at `apps/shopsaas` (working tree, including uncommitted Phase 1–18 work), not from prior reports or assumptions. Every class/file path below was verified to exist at the time of writing.

## 1. Overall architecture

AI Agent 2.0 has **two structurally separate customer-facing surfaces** plus one staff-facing surface, all built on the same underlying provider/credit/tool infrastructure:

- **Messenger auto-reply** — `App\Jobs\ProcessAiAgentMessage`, driven by `App\Http\Controllers\MessengerWebhookController`.
- **WhatsApp auto-reply** — `App\Jobs\ProcessWhatsAppAiAgentMessage`, driven by `App\Http\Controllers\WhatsAppWebhookController`. Deliberately *not* a shared implementation with the Messenger job — the two channels differ in message model, toggle key, and outbound send shape, and this codebase's own convention is "independent implementation per channel" rather than a shared abstraction layer.
- **Staff panel chat** — `App\Services\AI\AiChatService`, reached from `Tenant\AiChatController`. Authenticated, tenant-staff-only, and the *only* surface where the AI can call tools (look up real orders/products/customers, or propose a mutating action like creating an order).

The customer-facing surfaces (Messenger/WhatsApp) are deliberately **not** tool-calling agents. `App\Services\AI\AiAgentService` has no database access and no tools of its own — every fact it's given (price, stock, delivery charge, customer's own order history) is resolved deterministically by the calling job *before* the OpenAI call, by ordinary Laravel code, never by letting the model decide to invoke something. This is the core safety property of the whole architecture: the model that talks to an anonymous internet stranger physically cannot query the database or take an action, no matter what that stranger types.

## 2. Messenger flow

```
Meta webhook POST /webhook/messenger
  → MessengerWebhookController::handleEvent()   (HMAC signature verified, mid-deduped)
  → MessengerMessage row created (direction=in)
  → maybeDispatchAiAgent()                       (toggle/pause/credit/handoff pre-checks)
  → AiAgentMessageJob (tracking row, status=pending) + ProcessAiAgentMessage::dispatch()
  → [queued — see §"Queue processing" below]
  → ProcessAiAgentMessage::handle()
      → AiAgentMessageJob::claim()                (atomic pending→processing)
      → process(): re-check every precondition, build context, call AiAgentService
      → credit deducted (only after a successful OpenAI reply)
      → typing indicator, human delay, RE-CHECK handoff
      → MessengerApi::sendMessage()
      → MessengerMessage row created (direction=out, sent_by=ai)
      → AiAgentMessageJob::markCompleted()/markFailed()
```

## 3. WhatsApp flow

Same shape, one-for-one, in `WhatsAppWebhookController` → `ProcessWhatsAppAiAgentMessage` → `WhatsAppSendService`. Notable differences: WhatsApp attachments are never rehosted to a public URL (fetched on demand and inlined as base64 `data:` URIs instead — see §14), and outbound send/persistence is handled inside `WhatsAppSendService::sendText()` itself rather than a separate "create the row" step in the job.

## 4. Webhook → database → queue → AI → response flow (shared shape)

1. **Webhook** verifies Meta's `X-Hub-Signature-256` HMAC before touching anything (`hasValidSignature()` in both controllers, `hash_equals()` comparison).
2. **Database**: the inbound message is deduplicated (`mid`/`wamid` uniqueness, both an app-level `exists()` check and a DB `UNIQUE` constraint) and stored as a row (`messenger_messages` / `whatsapp_messages`).
3. **Queue**: a tracking row (`ai_agent_message_jobs` / `ai_whatsapp_message_jobs`, `status='pending'`) is written synchronously in the same webhook request, then the actual job is dispatched onto Laravel's `database` queue connection — see §23/29 for why this table exists at all.
4. **AI**: the job atomically claims its tracking row, rebuilds all context from scratch (never trusts anything the webhook already decided is still true), and calls `AiAgentService::generateReply()`.
5. **Response**: on success, credit is deducted, a human-feeling delay runs, handoff state is re-checked, and only then is the reply actually sent and persisted.

## 5. AiAgentService (`app/Services/AI/AiAgentService.php`)

- **Purpose**: assembles the OpenAI chat `messages` array (system prompt + history + current turn) and calls the bound `AiProviderInterface`. Text-only wrapper — the Messenger/WhatsApp jobs are responsible for resolving every fact injected into the prompt before calling this.
- **Input**: business name, conversation history, current message text, and up to 8 optional pre-resolved strings (style examples, customer name, tenant instructions, business knowledge, product data, customer memory, customer emotion, image URL, handoff notice).
- **Output**: `array{reply, input_tokens, output_tokens, model}|null` — `null` on any provider failure.
- **Database access**: none.
- **Tenant isolation**: N/A — receives only pre-scoped strings from its caller, never a tenant ID or a raw query.
- **Failure behavior**: never throws; returns `null` when the provider fails, which the caller must treat as "no reply."
- **Can spend AI credit**: no (it doesn't touch `AiCreditService` — the caller does, after this returns).
- **Can send a customer-facing message**: no — text generation only, no send capability.

## 6. AiProviderInterface (`app/Services/AI/Providers/AiProviderInterface.php`)

- **Purpose**: the provider abstraction. One method, `chat(array $messages, array $tools = []): AiProviderResponse`. Bound in `AppServiceProvider` based on `config('ai.provider')` (only `openai` implemented today).
- **Input/Output**: chat messages (+ optional OpenAI tool schema) in, a normalized `AiProviderResponse` out.
- **Database access**: none.
- **Failure behavior**: contract requires implementations to never throw.

## 7. OpenAiProvider (`app/Services/AI/Providers/OpenAiProvider.php`)

- **Purpose**: the only place OpenAI-specific HTTP/API-shape logic lives.
- **Input**: chat messages, optional tools array.
- **Output**: `AiProviderResponse::success(reply, inputTokens, outputTokens, model, toolCalls)` or `::failure()`.
- **Database access**: none (pure HTTP call to `config('ai.openai_base_url')`).
- **Failure behavior**: never throws. Catches transport errors, non-2xx API responses, and the "empty reply + no tool calls" case (typically a reasoning-family model spending its whole token budget on invisible reasoning — see §"reasoning_effort" in Document 9) — all three degrade to `failure()`.
- **Notable request-shape details**:
  - Sends `max_completion_tokens` (not `max_tokens` — newer models including `gpt-5-mini` reject the old parameter name).
  - Sends `reasoning_effort` (default `low`, `config('ai.reasoning_effort')`) only for models matching prefixes `gpt-5`, `o1`, `o3`, `o4` — sending it to any other model is itself an unsupported parameter.
  - Never logs `error.message` on a 401 response (OpenAI echoes a partial API key in that specific error), logs it for every other error status.
- **Can spend AI credit**: no — it only reports token usage; the caller decides whether/how to charge for it.

## 8. AiCreditService (`app/Services/AI/AiCreditService.php`)

- **Purpose**: single source of truth for a tenant's AI credit balance — every mutation (Super Admin allocation/adjustment, per-call usage deduction) and every read goes through here.
- **Input**: explicit `tenant_id` int on every method (never inferred from `app('currentTenant')` — this service is called from queued-job contexts where that binding doesn't exist).
- **Database access**: `ai_credit_accounts`, `ai_usage_ledger`, always `withoutGlobalScopes()->where('tenant_id', $tenantId)`.
- **Tenant isolation**: explicit `tenant_id` parameter on every public method; never reads it from anywhere ambient.
- **Failure behavior**: `recordUsage()`/`recordTranscriptionUsage()` return `null` if no wallet row exists (should never happen in practice since `hasCredit()` gates the call that would lead here) rather than silently creating one.
- **Concurrency safety**: `DB::transaction()` + `lockForUpdate()` on every balance mutation (same pattern this codebase uses for order-number generation).
- **Can spend AI credit**: this *is* the credit-spending mechanism.
- **Can send a customer-facing message**: no.

## 9. AiConversationStyleService (`app/Services/AI/AiConversationStyleService.php`)

- **Purpose**: builds a compact, tenant-specific "how does this business's own staff actually talk" style profile from real conversation history, to prevent replies reading as a generic call-center script.
- **Input**: `tenant_id`.
- **Output**: a single string — a one-line profile (typical length/emoji/address-term habits, computed from real data) followed by up to `config('ai.style_examples_max')` (default 6) real `Customer: ... / Reply: ...` pairs, or `''` if nothing usable exists yet.
- **Database access**: `messenger_messages` / `whatsapp_messages`, `withoutGlobalScopes()->where('tenant_id', ...)`.
- **Tenant isolation**: explicit tenant filter; cache keys are tenant-scoped (`ai_style_examples:{channel}:{tenant_id}`).
- **Failure behavior**: any exception is caught and logged, returns `''` (never blocks a reply).
- **Critical guarantee**: only ever learns from `sent_by='human'` rows — the AI's own past replies (`sent_by='ai'`) are explicitly excluded, so it never reinforces its own robotic phrasing.
- **Caching**: `Cache::remember()`, TTL `config('ai.style_cache_minutes')` (default 360 min), busted immediately (`forgetMessengerStyleCache()`/`forgetWhatsAppStyleCache()`) whenever a genuine human staff reply is sent through the panel.
- **Can spend AI credit**: no. **Can send a customer-facing message**: no.

## 10. AiCustomerMemoryService (`app/Services/AI/AiCustomerMemoryService.php`)

- **Purpose**: "what does this business already know about *this specific* customer" — currently, their single most recent order (number, status, delivery address).
- **Input**: `tenant_id` + a **channel-verified** identifier — Messenger `psid` or WhatsApp `wa_id` — never a value the customer typed in chat text.
- **Database access**: `orders`, matched on `messenger_psid` (written only at the moment that exact psid completed a real checkout) or `customer_phone` (WhatsApp's own Meta-authenticated sender number, normalized).
- **Tenant isolation**: explicit `tenant_id` filter alongside the channel identifier.
- **Deliberate design boundary**: this is *not* the same thing as `AiToolRegistry`'s `CustomerLookupTool` (search by typed phone/name) — that tool is only reachable from the authenticated staff panel, never from the public Messenger/WhatsApp surface, specifically because a customer could otherwise type a *different* customer's phone number and read back their data.
- **Failure behavior**: catches exceptions, returns `''`.
- **Can spend AI credit**: no. **Can send a customer-facing message**: no.

## 11. AiCustomerEmotionService (`app/Services/AI/AiCustomerEmotionService.php`)

- **Purpose**: surfaces one specific *verified fact* the model can't otherwise see — how many unanswered messages in a row this customer has sent, and for how long. Deliberately **not** a keyword/sentiment classifier ("angry", "!!!", ALL CAPS) — actual tone-reading is left to the model reading the real conversation text, which the system prompt explicitly governs.
- **Input**: `tenant_id`, channel identifier, current message ID.
- **Output**: a plain fact string ("This customer has sent 3 messages in a row without a reply yet, waiting since about 12 minute(s) ago.") or `''`.
- **Database access**: `messenger_messages`/`whatsapp_messages`, last 6 rows, tenant+identifier scoped.
- **Can spend AI credit**: no. **Can send a customer-facing message**: no.

## 12. AiProductKnowledgeService (`app/Services/AI/AiProductKnowledgeService.php`)

- **Purpose**: resolves which of the tenant's own real products were mentioned *anywhere in the conversation* (current message + recent history, not just the current message — this is what lets "COSRX Snail Cream টা নিতে চাই" → "দাম কত?" work without repeating the name), then pulls real, current price/stock via the existing `lookup_products` tool.
- **Input**: `tenant_id`, array of recent conversation texts.
- **Output**: compact `Name: (variant) price X, N in stock` lines for up to `config('ai.product_match_max')` (default 3) matched products.
- **Database access**: `products` (name scan, tenant-scoped, `is_active=1`, capped at `config('ai.product_match_scan_limit')`), then `AiToolRegistry::call('lookup_products', ...)`.
- **Critical field allow-list**: `formatProduct()` never includes `purchase_price` (wholesale cost) even though the underlying tool result carries it — that field is staff-panel-only.
- **Matching mechanism**: a cheap literal substring match against the tenant's own product names — not an AI call, not NLP, not vector search (deliberate "simple reliable architecture" choice).
- **Can spend AI credit**: no. **Can send a customer-facing message**: no.

## 13. AiAudioTranscriptionService (`app/Services/AI/AiAudioTranscriptionService.php`)

- **Purpose**: converts a customer's voice message to text via OpenAI's `/audio/transcriptions` endpoint (a genuinely different API shape — multipart file upload — from the chat endpoint), so downstream code (product matching, style examples, `generateReply()`) sees ordinary text.
- **Database access**: none directly (the caller writes the transcript back onto the message row).
- **Failure behavior**: every failure mode (missing config, network error, API error, empty transcript) degrades to `null`; the caller only calls `AiCreditService::recordTranscriptionUsage()` when this returns non-null, so a failed attempt is never charged.
- **Can spend AI credit**: indirectly yes — its caller (`ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage`) charges for it, but only on success. **Can send a customer-facing message**: no.

## 14. Image processing / image URL resolution

Two different mechanisms per channel, both implemented as `resolveImageUrl()` in the respective job:

- **Messenger** (`ProcessAiAgentMessage::resolveImageUrl()`): trivial — Messenger attachments are already rehosted to this app's own public storage at webhook time (`MessengerWebhookController::rehostAttachment()`), so this is a plain column read (`attachment_url`), never a new HTTP fetch.
- **WhatsApp** (`ProcessWhatsAppAiAgentMessage::resolveImageUrl()`): WhatsApp attachments are never rehosted (no confirmed persistent background worker on shared hosting — see §29). Instead this fetches the actual bytes on demand via `WhatsAppMediaService::fetch()` (the same Cloud API proxy path the panel's own media viewer uses) and inlines them as a base64 `data:` URI. Degrades to `null` on any failure (missing token, lookup/download failure, oversized body >20MB) — never throws, never charges anything (this runs entirely before the OpenAI call).

In both cases, only the **current** message's image is ever resolved — older image turns in history are replaced by an honest placeholder (`[customer sent a photo]`, see §17), never re-analyzed. `AiAgentService::userContent()` builds OpenAI's multimodal content-parts shape (`text` + `image_url` parts) only when an image URL is present; a text-only reply's request shape is otherwise byte-for-byte unchanged.

## 15. AI Tool Calling architecture

Tools exist **only** for the authenticated staff panel chat (`AiChatService` / `Tenant\AiChatController`) — the customer-facing Messenger/WhatsApp flow never receives a tools schema and can never reach one (see §5, §1).

- `App\Services\AI\Tools\AiToolRegistry` — the single execution entry point. `call()` executes a read-only `AiTool` immediately; `propose()` (mutating tools only) validates via `preview()` and returns a summary + resolved args without mutating anything.
- Every tool call strips `tenant_id`/`tenant_slug`/`currentTenant` out of the AI-supplied arguments *before* the tool ever sees them — defense in depth on top of every individual tool never reading such a key from `$args` in the first place. The real `$tenantId` always comes from the trusted server-side caller.
- Mutating tools (`CreateOrderTool`, `CreateProductTool`, `UpdateOrderStatusTool`, `CourierActionTool`) implement `AiMutatingTool` and are never executed directly from a model tool-call — they go through `AiPendingActionService::propose()` → stored as an `ai_pending_actions` row → require an explicit staff confirmation click (`Tenant\AiChatController::confirm()`) → `AiPendingActionService::confirm()`, which atomically claims the row (`status='pending'` conditional UPDATE, race-protected — a double-click/retry can never execute the same mutation twice) before calling `AiToolRegistry::call()` for real.
- Read-only tools: `ProductLookupTool`, `OrderLookupTool`, `CustomerLookupTool`, `SalesReportTool`.
- Bounded loop: `config('ai.chat_max_tool_iterations')` (default 5) caps how many tool round-trips one chat reply can take.

## 16. Human Handoff architecture (`app/Services/AI/AiHandoffService.php`)

- **Purpose**: the mechanism behind the system prompt's "never claim a human was notified" rule having a real exception to point to.
- **Deliberately not AI-decided**: whether to hand off is resolved by a deterministic, narrow phrase match (`customerRequestedHuman()` — specific multi-word phrases like "মানুষের সাথে কথা বলতে চাই", never a bare ambiguous word) in the calling job, *before* `AiAgentService` is ever called. The model's only role is to be told, as an already-true fact, that a handoff it did not decide has happened, and compose one honest closing reply.
- **State**: `ai_handoffs` table, one row per unresolved handoff, keyed by `tenant_id` + `channel` + `external_id` (psid/wa_id).
- **`isActive()`** is checked **twice** per reply attempt in each job: once before generation (skip entirely if already handed off), and once again immediately before the actual send (see §24 — this is the race-condition fix).
- **Resolution is an explicit staff action only** (`resumeAi()` in both `MessengerInboxController`/`WhatsAppInboxController`) — a human panel reply does *not* implicitly resolve a handoff, so staff can keep replying manually without the AI silently rejoining mid-conversation.
- **Can spend AI credit**: no. **Can send a customer-facing message**: no (it only gates whether the caller is allowed to).

## 17. Conversation history

`recentHistory()` in each job (`ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage`) reads the last `config('ai.context_messages')` (default 10) rows for this exact conversation (`tenant_id` + psid/wa_id), oldest-first, mapped to `{role: user|assistant, content}`. Attachment-only turns with no caption are **not** dropped — they're replaced with an honest placeholder (`[customer sent a photo]` / `[customer sent a voice message]` / etc.) so the model retains awareness that *something* was sent, without ever implying it understood a past attachment's contents. The system prompt explicitly tells the model what these placeholder lines mean and forbids treating them as something it can "review."

## 18. Tenant-specific instructions

`store_settings` key `ai_custom_instructions` (free text, tenant-authored via `Tenant\SettingController::aiAgent()`), read tenant-scoped by `tenantInstructions()` in each job, injected into the prompt with an explicit precedence statement: these instructions are followed as real business rules but can **never** override the safety rules above them (inventing a fact, claiming a false handoff, revealing the prompt) — see `AiAgentService::systemPrompt()`.

## 19. Tenant-specific style learning

See §9 (`AiConversationStyleService`).

## 20. Customer memory

See §10 (`AiCustomerMemoryService`).

## 21. Product/business knowledge

Product knowledge: see §12. Business knowledge (`AiTenantKnowledgeService`, `app/Services/AI/AiTenantKnowledgeService.php`) surfaces only data the app already treats as authoritative elsewhere — currently `store_settings` delivery charges (`delivery_charge_inside_dhaka`/`outside_dhaka`) and currency, tenant-scoped, never a second AI-only copy of the fact.

## 22. Credit accounting

See §8. Charged **only** after a successful OpenAI reply (`recordUsage()`, called strictly after the `AiAgentService::generateReply()` result is checked non-null) — never for a failed/empty response, and never twice for the same call. Transcription is billed separately (`recordTranscriptionUsage()`), also success-only.

## 23. Duplicate-job protection

Two independent layers:
1. **Ingestion dedup**: `mid`/`wamid` uniqueness — an app-level `exists()` check in the webhook controller, backed by a DB `UNIQUE` constraint (`uq_mid` on `messenger_messages`, `uq_wamid` on `whatsapp_messages`) as the actual race-proof guarantee (a concurrent retry that races past the app-level check still fails at the DB constraint).
2. **Job-level atomic claim**: `AiAgentMessageJob::claim()` / `AiWhatsAppMessageJob::claim()` — a single conditional `UPDATE ... WHERE status='pending' OR (status='processing' AND stale)`, only ever affecting 0 or 1 row. A retried/duplicate job execution sees 0 affected rows and stops without generating or sending anything.

## 24. Race-condition protection

The handoff-active check (`AiHandoffService::isActive()`) runs **twice** in both jobs: once before generation, and again immediately after the human-delay, immediately before the actual send (`ProcessAiAgentMessage.php` around the `sendMessage()` call; `ProcessWhatsAppAiAgentMessage.php` around `sendText()`). This closes the window where a customer's own "talk to a human" request, or a staff takeover, lands *during* the OpenAI round-trip or the deliberate humanization delay (both can span several seconds) — without this second check, an already-generated reply could still reach the customer after a genuine handoff. Credit is still charged either way (the OpenAI cost was genuinely incurred); only the send is skipped.

## 25. sent_by attribution

`messenger_messages.sent_by` / `whatsapp_messages.sent_by` is `'human'` or `'ai'`, set as a **hardcoded literal at each trusted server-side call site** — never derived from request/customer input:
- `ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage` write `'ai'` on the AI's own outbound reply.
- `MessengerInboxController::reply()` / `WhatsAppInboxController::reply()` (a genuine staff panel reply) default to `'human'`.

This is what lets `AiConversationStyleService` learn only from real human replies (§9) and what a future audit would check first if "the AI is copying its own phrasing" were ever reported.

## 26. Failure handling

Every step in both jobs re-verifies its own precondition rather than trusting an earlier check (webhook-time or earlier-in-job) is still true — tenant could be gone, toggles could have flipped, credit could be exhausted, a handoff could now exist. Every failure path returns `false` (job marked `'failed'` in its tracking row) without throwing and without ever sending a fallback/error message to the customer — silence is the deliberate, safe default on any unrecoverable failure, never a fabricated reply. Uncaught exceptions are caught at the top of `handle()`, logged, and also mark the job `'failed'` — deliberately not rethrown, since `markFailed()` already makes any further retry attempt a safe no-op via the `claim()` guard.

## 27. Logging

`Log::warning()`/`Log::info()` throughout, routed through the app's default `config('logging.default')` (`LOG_CHANNEL`, defaults to `stack` → `single`, i.e. `storage/logs/laravel.log`). Consistent safety rule across every AI-related log call audited: never log raw exception messages (`$e->getMessage()`) or provider/Graph API error *message* text (only short machine-readable fields like `error.type`/`error.code`/`finish_reason`) — both can echo request/credential details. Never log the OpenAI API key. Never log raw customer message text or phone numbers in AI-pipeline log lines (verified — see Document 6).

## 28. Caching

The only AI-pipeline cache usage is `AiConversationStyleService`'s style-example blocks (§9) — tenant-scoped keys (`ai_style_examples:{channel}:{tenant_id}`), TTL `config('ai.style_cache_minutes')`, invalidated on a genuine new human reply. No other AI service caches anything (product/business/customer-memory/emotion lookups are cheap, tenant-scoped, per-request queries).

## 29. Database dependencies

AI Agent 2.0 is **not migration-driven** — its schema lives in `database/sql/chunk*.sql`, not `database/migrations/`. Every AI-related table/column is guarded by a `tablesReady()`/`columnsReady()`-style check (e.g. `AiAgentMessageJob::tablesReady()`, `MessengerMessage::sentByColumnReady()`) so an environment where a chunk hasn't been imported yet degrades to "feature not available" rather than a raw SQL error. See Document 4 for exactly which chunks this depends on and their current known-committed status.

Key tables: `ai_credit_accounts`, `ai_usage_ledger`, `ai_agent_message_jobs`, `ai_whatsapp_message_jobs`, `ai_handoffs`, `ai_pending_actions`, plus the pre-existing `messenger_messages`/`whatsapp_messages`/`orders`/`products`/`product_variants`/`store_settings` tables the AI services read from.
