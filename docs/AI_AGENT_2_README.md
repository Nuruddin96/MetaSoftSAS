# AI Agent 2.0 — README

Single entry point for AI Agent 2.0's documentation set. Start here.

## What AI Agent 2.0 is

An AI customer-support agent for ShopSaaS tenants, replying automatically to customers on **Messenger** and **WhatsApp** in the tenant's own store voice — plus a separate, authenticated AI assistant for tenant staff inside the panel (order/product/customer lookups, and staff-confirmed order/product creation). Built on OpenAI (`gpt-5-mini` by default) behind a provider abstraction, with a tenant-facing credit/wallet system, tenant-specific style learning, verified customer memory, and a human-handoff mechanism.

## Main features

- Context-aware conversation (follow-up questions resolved without repeating earlier details)
- Learns each tenant's own real communication style from their staff's actual past replies
- Real, verified product price/stock/business data — never invented
- Verified customer memory (most recent order) for identity the channel itself authenticated
- Image and voice-message understanding, with honest failure fallback
- Emotion-aware tone adaptation
- Honest, real human handoff — never a false "I've notified the team" claim
- Per-reply credit accounting, tenant-scoped
- Duplicate-webhook and race-condition protection

## Architecture overview

See **[AI_AGENT_2_ARCHITECTURE.md](./AI_AGENT_2_ARCHITECTURE.md)** for the full component-by-component breakdown (Messenger/WhatsApp flow, every AI service, tool-calling, handoff, credit accounting, caching, and database dependencies).

## Capabilities & limitations

See **[AI_AGENT_2_CAPABILITIES.md](./AI_AGENT_2_CAPABILITIES.md)** — a non-technical explanation of what the AI can and cannot guarantee. Read this before making promises to a tenant about what the AI will do.

## Tenant configuration

See **[AI_AGENT_2_TENANT_CONFIGURATION.md](./AI_AGENT_2_TENANT_CONFIGURATION.md)** — exactly how to turn AI Agent on, what each setting does, and how to write a good AI Instructions block (with a full example).

## Security & tenant isolation

See **[AI_AGENT_2_SECURITY.md](./AI_AGENT_2_SECURITY.md)** — the trust boundaries, channel-authentication mechanisms, and the specific threats this architecture protects against.

## Deployment

See **[AI_AGENT_2_DEPLOYMENT.md](./AI_AGENT_2_DEPLOYMENT.md)** — the full checklist, environment variables, and (critically) which SQL chunks are and are not yet deployed. **`chunk37.sql`–`chunk40.sql` are not yet committed to git as of this writing and have not been deployed.**

## Rollback

See **[AI_AGENT_2_ROLLBACK.md](./AI_AGENT_2_ROLLBACK.md)** — how to disable AI instantly (per-tenant or platform-wide), revert code, and what must never be manually deleted.

## Monitoring

See **[AI_AGENT_2_OPERATIONS.md](./AI_AGENT_2_OPERATIONS.md)** — what to watch in production, with verified log/query commands.

## Testing

See **[AI_AGENT_2_TEST_MATRIX.md](./AI_AGENT_2_TEST_MATRIX.md)** — the full 40-scenario production-readiness matrix, honestly distinguishing automated/code-traced coverage from prompt-governed behavior that only a live conversation can confirm.

## Response quality guide

See **[AI_AGENT_2_RESPONSE_QUALITY.md](./AI_AGENT_2_RESPONSE_QUALITY.md)** — the intended communication style, with BAD → GOOD examples.

## Known risks

- **Image-follow-up product matching** has a documented gap: if a customer asks about a previously-sent photo in a later message, the automatic real-price/stock re-verification only reliably re-triggers on a literal product-name text match — see `AI_AGENT_2_CAPABILITIES.md` and `AI_AGENT_2_ARCHITECTURE.md` §14.
- **`chunk37.sql`–`chunk40.sql` are uncommitted and undeployed** — the corresponding code degrades gracefully (feature silently unavailable) but is not fully functional until these are committed, deployed, and manually imported. See `AI_AGENT_2_DEPLOYMENT.md`.
- **No persistent queue worker** — this project relies entirely on a single cron-triggered `schedule:run` entry to process AI replies at all. If that cron entry ever stops running, AI replies silently stop with no obvious error. See `AI_AGENT_2_OPERATIONS.md`/`AI_AGENT_2_ROLLBACK.md`.
- **Tone/style quality is prompt-governed, not code-enforced.** A future OpenAI model change could shift behavior with no automated test catching it, since this suite deliberately never asserts exact AI-generated text. See `AI_AGENT_2_TEST_MATRIX.md`.
- **No automated monitoring for reply length/repeated-clarification quality drift** — currently a manual/periodic review process only.

## Future improvements

Not committed to, not started — flagged here only as reasonable directions if the above risks prove to matter in practice:
- A structured tenant "business info" form (location, phone, opening hours, policies, payment methods) instead of relying on the free-text AI Instructions field for all of it.
- Vision-to-catalog product matching, to close the image-follow-up re-verification gap.
- Automated reply-length / repeated-question drift monitoring.
- A genuinely persistent queue worker, if/when this project migrates off shared hosting (already anticipated by the existing `subdomain` tenancy mode built for a VPS migration — see the top-level `CLAUDE.md`).
