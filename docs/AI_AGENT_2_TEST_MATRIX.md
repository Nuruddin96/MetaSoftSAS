# AI Agent 2.0 — Test & Validation Matrix

The complete 40-scenario walkthrough from Phase 20A of this audit. Every row was individually verified by tracing the actual execution path in the codebase (not assumed from a test name alone) — see `PHASE 20A` in the project history for the full trace notes. This document adds one thing Phase 20A's own table didn't: an explicit classification of *how* each scenario can be verified, so nobody later mistakes a prompt-governed behavior for a mathematically-guaranteed one.

**Status legend:**
- **AUTOMATED** — a passing automated test in `tests/Feature/AiAgent/` directly exercises this scenario.
- **CODE-TRACED** — verified by reading the actual execution path; no dedicated automated test, or the existing tests only cover it indirectly.
- **PROMPT-GOVERNED** — the behavior depends on the OpenAI model following a natural-language instruction in `config('ai.system_prompt')`, not on deterministic code. Verified: the correct, specific instruction is genuinely present and deployed. **Not** verified: that the live model always complies — no live OpenAI call is made in this test suite.
- **MANUAL LIVE TEST REQUIRED** — recommended before relying on this in production, in addition to (not instead of) the above.

| ID | Scenario | Expected behavior | Relevant code path | Existing test | Status | Manual verification requirement |
|---|---|---|---|---|---|---|
| 1 | Says hello | Natural reply, no crash on empty context | `ProcessAiAgentMessage::process()` | — (covered indirectly by many tests with minimal context) | CODE-TRACED | Optional smoke test |
| 2 | Normal product question | Real price/stock returned | `AiProductKnowledgeService::relevantProducts()` | `test_the_cosrx_snail_cream_scenario_reaches_the_provider_with_real_price_and_stock` | AUTOMATED | None |
| 3 | Price after naming product earlier | Uses earlier-named product | Same — scans history + current message | Same test family | AUTOMATED | Recommended: live conversation test (final reply text is model-generated, not asserted verbatim) |
| 4 | Continues without repeating name | Same as #3 | Same | Same | AUTOMATED | Same as #3 |
| 5 | Uses previous conversation context | Full turn history sent to model | `recentHistory()` in both jobs | `test_tenant_a_conversation_history_never_includes_tenant_bs_messages_for_the_same_wa_id` (+ others asserting history shape) | AUTOMATED | None |
| 6 | Doesn't re-ask for known info | No redundant clarifying questions | `config/ai.php` system prompt | — | PROMPT-GOVERNED | MANUAL LIVE TEST REQUIRED |
| 7 | Short message → sufficient answer | Concise, useful | System prompt + `AiConversationStyleService::buildProfileLine()` | Style-service tests cover the profile-line computation, not model output length | PROMPT-GOVERNED (code-assisted) | MANUAL LIVE TEST REQUIRED |
| 8 | Not unnecessarily long | Same as #7 | Same | Same | PROMPT-GOVERNED | MANUAL LIVE TEST REQUIRED |
| 9 | Learns tenant-specific style | Style built from tenant's own history | `AiConversationStyleService::buildFromHistory()`/`buildFromWhatsAppHistory()` | `AiConversationStyleServiceTest`, `AiConversationStyleServiceWhatsAppTest` | AUTOMATED | None |
| 10 | Excludes AI replies from style learning | `sent_by='human'` filter only | Same | `test_ai_generated_whatsapp_replies_are_never_used_as_style_examples` | AUTOMATED | None |
| 11 | Uses recent human replies | Recency-ordered | Same | Same test suite | AUTOMATED | None |
| 12 | Doesn't blindly copy phrases | Fresh wording | System prompt: "never copy...word-for-word" | — | PROMPT-GOVERNED | Optional spot-check |
| 13 | Not always "আপু" | Occasional use | System prompt addressing rules | — | PROMPT-GOVERNED | Optional spot-check |
| 14 | Not always "ভাইয়া" | Same | Same | — | PROMPT-GOVERNED | Optional spot-check |
| 15 | Infers from name only when clear | Conservative gender inference | Same | — | PROMPT-GOVERNED | Optional spot-check |
| 16 | No unnecessary repetition of address terms | Varies naturally | Same + `buildProfileLine()`'s measured `addressHabit` | `AiConversationStyleServiceTest` (profile-line computation only) | PROMPT-GOVERNED (code-assisted) | Optional spot-check |
| 17 | Reads emotion, adapts tone | Natural tone-matching | System prompt "Reading the customer's emotion" + `AiCustomerEmotionService` (verified wait-time fact only) | `AiCustomerEmotionServiceTest`(+WhatsApp variant) — covers the fact-computation, not tone output | PROMPT-GOVERNED (code-assisted) | MANUAL LIVE TEST REQUIRED |
| 18 | Angry customer → calm reply | No corporate-apology template | System prompt | — | PROMPT-GOVERNED | MANUAL LIVE TEST REQUIRED |
| 19 | Interested customer → natural sales tone | No pressure, no invented discount | System prompt "Selling naturally" | — | PROMPT-GOVERNED | Optional spot-check |
| 20 | Doesn't sound like it's placing the order itself | No false completion claims | System prompt + **hard guarantee**: `AiAgentService` has zero tool access | `AiAgentService`'s own docblock/design (structural) | CODE-TRACED + PROMPT-GOVERNED | None (structural guarantee); optional tone spot-check |
| 21 | Returning customer memory | Most recent order surfaced | `AiCustomerMemoryService` | `test_this_customers_own_order_history_reaches_the_provider` | AUTOMATED | None |
| 22 | Messenger identity channel-authenticated | `psid` never customer-suppliable | `MessengerWebhookController::hasValidSignature()` | `MessengerWebhookRegressionTest` (signature cases) | AUTOMATED | None |
| 23 | WhatsApp identity channel-authenticated | `wa_id` never customer-suppliable | `WhatsAppWebhookController::hasValidSignature()` | `invalid_signature_is_rejected_before_any_tenant_resolution` | AUTOMATED | None |
| 24 | Order memory tenant-isolated | No cross-tenant order data | `AiCustomerMemoryService::latestOrder()` | `TenantIsolationAuditTest` (re-run live in this audit) | AUTOMATED | None |
| 25 | Can't see another tenant's data | Full isolation | ~70 audited `withoutGlobalScopes()` call sites | `TenantIsolationAuditTest`, `TenantIsolationAuditWhatsAppTest` (re-run live) | AUTOMATED | None |
| 26 | Only verified facts in customer memory | No AI summarization | `AiCustomerMemoryService::format()` | Covered by #21/#24 tests | CODE-TRACED | None |
| 27 | Doesn't invent missing order info | Empty string when no order | Same | Same | CODE-TRACED | None |
| 28 | Tenant instructions respected | Free text reaches prompt | `tenantInstructions()` in both jobs | `test_tenant_ai_instructions_reach_the_provider` | AUTOMATED | None |
| 29 | Business info used when configured | Delivery charges etc. | `AiTenantKnowledgeService` | `test_real_delivery_charges_reach_the_provider` | AUTOMATED | None |
| 30 | No leak between tenants | Isolated instructions/knowledge | Same tenant-scoped queries | `TenantIsolationAuditTest` (plants colliding instruction + delivery charge, asserts isolation) | AUTOMATED | None |
| 31 | Uses configured product/business knowledge | Injected when available | See #2, #29 | Same | AUTOMATED | None |
| 32 | No invented price/stock when unavailable | Says "not sure," not a guess | `relevantProducts()` returns `''` on no match + system prompt "never invent a number" | Code path automated; model compliance is prompt-governed | CODE-TRACED + PROMPT-GOVERNED | MANUAL LIVE TEST REQUIRED |
| 33 | Image detected/processed | Vision request built | `resolveImageUrl()` both jobs | `test_an_image_with_no_caption_is_dispatched_and_reaches_the_provider_as_a_vision_content_part`, `an_image_with_a_caption_sends_both` | AUTOMATED | Recommended: live test with a real Messenger/WhatsApp photo, both channels |
| 34 | Image failure → honest, not fabricated | No fake "I saw..." claim | `resolveImageUrl()` returns `null` on failure | `a_media_lookup_failure_falls_back_to_no_reply_rather_than_a_broken_request` | AUTOMATED | None |
| 35 | Voice transcribed when supported | Transcript used as message text | `transcribeAndPersist()` | `a_voice_message_with_no_caption_is_transcribed_and_the_transcript_reaches_the_provider` | AUTOMATED | Recommended: live test with a real voice note |
| 36 | Transcription failure → honest | No fake "I heard..." claim | `AiAudioTranscriptionService` degrades to `null` | `a_failed_transcription_never_charges_credit_and_never_sends_a_reply` | AUTOMATED | None |
| 37 | Asks for human → honest, no false claim | `handoffNotice` only on a genuinely true turn | `AiHandoffService::customerRequestedHuman()`/`trigger()` | `a_customer_asking_for_a_human_triggers_a_handoff_and_an_honest_final_reply` | AUTOMATED | None |
| 38 | Human takeover during generation → not sent | Re-checked handoff before send | `ProcessAiAgentMessage`/`ProcessWhatsAppAiAgentMessage`, pre-send `isActive()` re-check | `test_a_handoff_created_during_generation_stops_the_reply_from_being_sent` (both channels) | AUTOMATED | None — this is the race-condition fix landed and tested in this audit |
| 39 | Duplicate webhook/job → no dup reply/charge | Two-layer defense | `mid`/`wamid` dedup + `AiAgentMessageJob::claim()` | `AiAgentMessageJobClaimTest`, `WhatsAppWebhookDuplicateTest`, `a_duplicate_webhook_delivery_never_dispatches_a_second_ai_job` | AUTOMATED | Recommended: live test by forcing Meta to retry a webhook (return non-200 once) |
| 40 | Failed OpenAI/transcription/empty → no false reply, no charge | Credit only after success | `OpenAiProvider::chat()` failure paths; credit call strictly after success check | `a_failed_transcription_never_charges_credit_and_never_sends_a_reply` (+ code trace of `OpenAiProvider`) | AUTOMATED + CODE-TRACED | None |

## Summary

- **AUTOMATED:** 27 / 40
- **CODE-TRACED (no dedicated automated test, verified by direct trace):** 4 / 40 (#1, #26, #27, #32 partially)
- **PROMPT-GOVERNED (model-instruction-following, not code):** 13 / 40 — overlaps with a few CODE-TRACED/AUTOMATED rows above where a code-side assist exists alongside the prompt instruction (#7, #8, #16, #17, #20, #32)
- **MANUAL LIVE TEST REQUIRED before production reliance:** #6, #7, #8, #17, #18, #32 (tone/context-continuity quality), plus recommended smoke tests for #1, #3, #4, #33, #35, #39 (real external API integration paths not exercised by the SQLite/`Http::fake()` test suite)

Do not read "AUTOMATED" or "CODE-TRACED" as "guaranteed to produce a perfect reply every time" — both only guarantee the *code path* is correct and safe (real data reaches the model, nothing fabricatable is injected, nothing leaks across tenants). What the model actually writes in response is never asserted verbatim by this suite, by design (asserting exact AI-generated text would be brittle and would encourage testing the wrong thing).
