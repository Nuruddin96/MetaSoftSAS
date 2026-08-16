# AI Agent 2.0 — Security & Tenant Isolation

Verified by direct code trace during Phase 20A of this audit, then re-verified again for this document. Where a claim below is backed by a specific automated test, the test name is given — but every claim was also independently confirmed by reading the actual code path, not inferred from the test existing.

## tenant_id trust boundary

Every AI service method that touches the database takes `tenant_id` as an **explicit parameter from the trusted server-side caller** (`ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage`, which get it from the job's own constructor argument, which was itself set by the webhook controller from the verified inbound message's own `tenant_id` column — never from anything in the request body or AI-generated text). No AI service reads `app('currentTenant')` (which wouldn't even be bound inside a queued job) and no AI service accepts a tenant ID from an AI tool-call argument — see "customer input cannot select another tenant" below.

## Channel-authenticated identity

**Messenger PSID**: `MessengerWebhookController::handleEvent()` reads the customer's `psid` from Meta's webhook payload, which is only accepted after HMAC signature verification (`hasValidSignature()`, `X-Hub-Signature-256`, timing-safe `hash_equals()` comparison against `hash_hmac('sha256', $request->getContent(), config('messenger.app_secret'))`). A request without a valid signature never reaches any handler that reads `psid`.

**WhatsApp wa_id**: identical mechanism in `WhatsAppWebhookController` (same signature-verification shape, `config('whatsapp.app_secret')`-equivalent).

Every AI service that personalizes a reply to "this specific customer" (`AiCustomerMemoryService`, `AiCustomerEmotionService`) is keyed exclusively on this channel-verified identifier — never on anything read from the message *text*.

## Order lookup security

`AiCustomerMemoryService` matches orders on `messenger_psid` (written only at the moment that exact, Meta-verified psid completed a real checkout) or `customer_phone` (WhatsApp's sender number, itself Meta-authenticated on every inbound webhook). This is deliberately a **different, narrower** mechanism than `Tools\CustomerLookupTool`/`Tools\OrderLookupTool` (search by typed phone/name) — those tools are reachable *only* from the authenticated staff panel chat, never from the public Messenger/WhatsApp surface, specifically because an anonymous customer could otherwise type a different customer's phone number or name into the chat and read back their order/address/spend history.

## sent_by security

`sent_by` (`'human'` vs `'ai'`) is a **hardcoded literal at each trusted call site**, never derived from request input:
- `ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage` write `'ai'` unconditionally on their own outbound send.
- `MessengerInboxController::reply()`/`WhatsAppInboxController::reply()` (authenticated staff panel replies) default to `'human'`.

A customer cannot cause a message to be recorded as `sent_by='human'`, and a staff member's own genuine reply cannot be mislabeled `'ai'` — there is no code path where this value is read from anywhere other than the literal at the call site. This is what makes `AiConversationStyleService`'s "only learn from real human replies" guarantee actually hold.

## AI tool authorization

Tools (`AiToolRegistry`) are reachable **only** from `AiChatService`, which is itself reachable only from `Tenant\AiChatController` — an authenticated, tenant-staff-only route (`auth:tenant` guard). The public Messenger/WhatsApp customer-facing flow (`AiAgentService`) never receives a tools schema and structurally cannot invoke one, regardless of what a customer types (see `AiAgentService`'s own docblock: "This class has NO database access and NO tools of its own"). On top of that, `AiToolRegistry::call()`/`propose()` additionally strip `tenant_id`/`tenant_slug`/`currentTenant` out of any AI-supplied arguments before a tool implementation ever sees them.

## Tenant-scoped database queries / `withoutGlobalScopes()` usage

Every AI-related model query audited (~70 call sites across `app/Services/AI/*`, `app/Services/AI/Tools/*`, and both jobs) uses `withoutGlobalScopes()` **paired with an explicit `->where('tenant_id', $tenantId)`** using the trusted server-derived tenant ID. `withoutGlobalScopes()` is used deliberately here — not to bypass tenant scoping, but because the automatic `BelongsToTenant` scope depends on `app('currentTenant')`, which is never bound inside a queued job or a super-admin/system context; relying on the ambient scope in that context would silently return **unscoped** (all-tenant) data instead of failing loudly, which is exactly why every one of these call sites re-applies the filter explicitly instead.

## Customer input cannot select another tenant

Confirmed by direct trace: no AI service or job ever reads a tenant identifier from message text, an AI tool-call argument, or any other customer-controllable input. Re-confirmed live by re-running `TenantIsolationAuditTest`/`TenantIsolationAuditWhatsAppTest` (maximally-adversarial: identical psid, identical product name, identical order-number-format across two tenants) during Phase 20A — passing.

## Customer input cannot choose `sent_by`

See "sent_by security" above.

## Customer input cannot control credit ownership

`AiCreditService`'s every public method takes `tenant_id` as an explicit int parameter from the trusted caller; nothing in the credit-deduction path (`recordUsage()`/`recordTranscriptionUsage()`, both called from inside the job with `$this->tenantId`) reads anything from the message or the OpenAI response other than token counts.

## Customer input cannot authorize tools

Structural, not policy-based: the customer-facing surface has no tools schema at all (see "AI tool authorization" above) — there is no permission check to bypass because there is no invocation path to reach.

## Duplicate webhook protection

Two independent layers (see `AI_AGENT_2_ARCHITECTURE.md` §23 for the full detail): app-level `mid`/`wamid` existence check at ingestion, backed by a DB `UNIQUE` constraint that catches the race the app-level check alone would miss.

## Race protection

`AiAgentMessageJob::claim()`/`AiWhatsAppMessageJob::claim()` — single atomic conditional `UPDATE`, only one execution ever wins the `pending → processing` transition.

## Handoff race protection

The specific fix verified in this audit's Phase 18/20A pass: `AiHandoffService::isActive()` is checked **twice** per reply — once before generation, once again immediately before the actual send, after the OpenAI round-trip and the deliberate humanization delay have both elapsed. See `AI_AGENT_2_ARCHITECTURE.md` §24.

## Secret / API-key logging rules

Verified by grep across every `Log::` call in `app/Services/AI/*` and both jobs: the OpenAI API key is never logged. Provider/Graph API error `message` text is deliberately excluded from logs on 401 responses specifically (OpenAI's own 401 response echoes back a partial API key) and only short, machine-readable enum-like fields (`error.type`, `error.code`, `finish_reason`) are logged elsewhere. Raw exception messages (`$e->getMessage()`) are never logged in the AI pipeline — only `get_class($e)`.

## PII logging rules

Verified by the same grep sweep: no raw customer message text, phone number, or address appears in any `Log::` call across the AI pipeline. Log context arrays consistently carry only `tenant_id`, message/job IDs, and short diagnostic enums.

---

## Threats this architecture explicitly protects against

- A customer on Messenger typing another customer's phone number and reading back their order/address (blocked structurally — customer memory is keyed only on the channel-verified identifier, never customer-typed text; the tools that *do* search by typed phone/name are unreachable from this surface at all).
- A customer crafting a message designed to make the AI reveal its system prompt, another tenant's data, or internal identifiers (the system prompt explicitly instructs against this, and there is no code path by which customer text could reach another tenant's data regardless of what the model is told to do, since tenant scoping happens in Laravel code before the model is ever called — a prompt-injection attempt has nothing to inject into that would actually change which rows get queried).
- A customer's message being misattributed as a staff reply, or a staff reply being misattributed as AI-generated (structurally impossible — `sent_by` is never read from customer/request input).
- A duplicate webhook delivery (Meta's own documented at-least-once behavior) producing a duplicate AI reply or a duplicate credit charge (two independent layers, see above).
- A customer asking for a human, or a staff member taking over mid-conversation, being ignored because the AI's reply was already "in flight" (the handoff race-check fix).
- The AI fabricating a price, stock level, order status, or "a human has been notified" claim (enforced by never giving the model fabricatable data to begin with — verified facts are injected deterministically by Laravel code, not looked up by the model itself on this surface — plus explicit, repeated prompt-level prohibitions as a second layer).
- A tenant's own OpenAI spend being charged to another tenant's credit balance, or charged at all for a failed/empty AI response.
