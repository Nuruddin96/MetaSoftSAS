<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppAiAgentMessage;
use App\Models\AiWhatsAppMessageJob;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\AI\AiCreditService;
use App\Services\AI\AiHandoffService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ONE webhook URL handles WhatsApp Cloud API events for every tenant, same
 * "single global URL, tenant resolved per-event" shape as
 * MessengerWebhookController — Meta only supports one webhook URL per App.
 *
 * Structurally independent from Messenger: separate config, separate model
 * (whatsapp_messages, not messenger_messages), separate resolution key
 * (phone_number_id, not a Page id). Mirrors Messenger's PROVEN patterns
 * (signature verification, dedup-by-platform-message-id, fail-safe error
 * handling, withoutGlobalScopes()+explicit tenant_id since this route never
 * has app('currentTenant') bound) without copying its payload-shape-specific
 * code, since the WhatsApp Cloud API webhook body is structured differently
 * from Messenger's (entry[].changes[].value.{messages,statuses,contacts}
 * rather than entry[].messaging[]).
 *
 * Outgoing sends (WhatsAppSendService) and onboarding (WhatsAppConnectController,
 * which populates whatsapp_phone_numbers) live elsewhere, not in this
 * controller — it only ever reads that table, never writes to it. Inbound
 * media is deliberately never downloaded/re-hosted here either: this
 * controller stores the Cloud API media id via raw_payload and nothing more
 * (see handleIncomingMessage() below); WhatsAppMediaService fetches it live,
 * on demand, from WhatsAppInboxController::media() when a tenant actually
 * opens the thread — not at webhook time, since a synchronous Graph API
 * round-trip here would add latency/failure surface to a request Meta
 * expects acked fast, and this app has no confirmed background queue worker
 * on shared hosting to defer it to instead.
 */
class WhatsAppWebhookController extends Controller
{
    /** WhatsApp Cloud API's documented terminal delivery states, in the order they normally arrive. */
    protected const STATUS_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    /** Meta calls this once (GET) to verify the webhook URL. Field names arrive as hub_mode/hub_verify_token/hub_challenge — PHP replaces dots with underscores in query keys, same as MessengerWebhookController::verify(). */
    public function verify(Request $request)
    {
        if ($request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === config('whatsapp.verify_token')) {
            return response($request->query('hub_challenge'), 200);
        }

        abort(403);
    }

    /** Meta calls this (POST) every time a message/status event happens. */
    public function receive(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WhatsApp webhook: rejected request with invalid or missing X-Hub-Signature-256.', [
                'ip' => $request->ip(),
            ]);

            abort(403);
        }

        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                // Only message/status traffic is in scope this phase —
                // template_status_update, phone_number_quality_update,
                // account_alerts etc. are ignored, not errors, so a future
                // phase can start handling them without this controller
                // having ever mishandled them as malformed messages.
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                $this->handleChange($change['value'] ?? [], $request);
            }
        }

        return response()->json(['ok' => true]);
    }

    protected function handleChange(array $value, Request $request): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            return;
        }

        $owner = $this->resolvePhoneNumberOwner($phoneNumberId);

        if (! $owner) {
            // Unknown/disconnected number — never insert tenant-scoped data
            // for a phone_number_id we can't attribute. Not an error: this
            // is the expected shape of a webhook event for a number that
            // was disconnected after subscribing, or (in a multi-tenant-app
            // sense) simply isn't one of ours.
            Log::info('WhatsApp webhook: unknown or inactive phone_number_id, ignoring.', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return;
        }

        $contactsByWaId = collect($value['contacts'] ?? [])
            ->filter(fn ($c) => isset($c['wa_id']))
            ->keyBy('wa_id');

        foreach (($value['messages'] ?? []) as $message) {
            try {
                $this->handleIncomingMessage($message, $owner, $contactsByWaId);
            } catch (\Throwable $e) {
                // One malformed/unexpected message in a batch must never take
                // the rest of the webhook payload down with it.
                Log::error('WhatsApp webhook: failed to process an incoming message.', [
                    'tenant_id' => $owner->tenant_id,
                    'wamid' => $message['id'] ?? null,
                    'type' => $message['type'] ?? null,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (($value['statuses'] ?? []) as $status) {
            try {
                $this->handleStatus($status, $owner);
            } catch (\Throwable $e) {
                Log::error('WhatsApp webhook: failed to process a status event.', [
                    'tenant_id' => $owner->tenant_id,
                    'wamid' => $status['id'] ?? null,
                    'status' => $status['status'] ?? null,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolves which tenant owns an incoming phone_number_id — the ONLY
     * server-side source of truth for tenant attribution on this route.
     * Nothing from the request body beyond this Meta-controlled identifier
     * is ever trusted to pick a tenant (mirrors MessengerWebhookController::
     * resolvePageOwner()'s "never trust an attacker-shaped field" posture,
     * and FacebookConnectController's "never trust a submitted id" rule).
     *
     * withoutGlobalScopes() is required, not optional, here: this is a
     * central, non-tenant-prefixed route, so app('currentTenant') is never
     * bound — BelongsToTenant's global scope would silently filter to
     * nothing (or, if some other request happened to bind a tenant into a
     * shared container instance, the WRONG tenant) rather than search
     * across all tenants the way this lookup must.
     */
    protected function resolvePhoneNumberOwner(string $phoneNumberId): ?object
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return null;
        }

        $phone = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('phone_number_id', $phoneNumberId)
            ->where('is_active', 1)
            ->first();

        if (! $phone) {
            return null;
        }

        return (object) [
            'tenant_id' => $phone->tenant_id,
            'whatsapp_phone_number_id' => $phone->id,
        ];
    }

    protected function handleIncomingMessage(array $message, object $owner, $contactsByWaId): void
    {
        $waId = $message['from'] ?? null;
        $wamid = $message['id'] ?? null;

        if (! $waId) {
            return;
        }

        // Meta's webhook delivery is at-least-once, same as Messenger — the
        // same event can be POSTed again after a timeout or non-2xx
        // response. This exists() check is the fast-path skip; the
        // UNIQUE(wamid) constraint below (see chunk26.sql) is the actual
        // guarantee that closes the race between this check and the
        // insert, not this check itself.
        if ($wamid && WhatsAppMessage::withoutGlobalScopes()->where('wamid', $wamid)->exists()) {
            return;
        }

        $type = $message['type'] ?? 'unknown';
        $name = $contactsByWaId->get($waId)['profile']['name'] ?? null;

        $content = $this->extractContent($type, $message);

        $createdAt = isset($message['timestamp']) && is_numeric($message['timestamp'])
            ? Carbon::createFromTimestamp((int) $message['timestamp'])
            : now();

        $attributes = array_merge([
            'tenant_id' => $owner->tenant_id,
            'whatsapp_phone_number_id' => $owner->whatsapp_phone_number_id,
            'wa_id' => $waId,
            'wamid' => $wamid,
            'customer_name' => $name,
            'message_type' => $type,
            // Never downloaded/re-hosted here — the Cloud API media id
            // (in raw_payload, e.g. $message['image']['id']) is all this
            // webhook stores; WhatsAppMediaService fetches it live, on
            // demand, when a tenant opens the thread (see this class's
            // docblock and WhatsAppMessage::inboundMediaId()).
            // attachment_url intentionally stays null for inbound rows.
            'attachment_url' => null,
            'raw_payload' => $message,
            'direction' => 'in',
            'status' => 'new',
            'created_at' => $createdAt,
        ], $content);

        $stored = null;

        try {
            $stored = WhatsAppMessage::withoutGlobalScopes()->create($attributes);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }
            // Race: a concurrent retry of this same wamid won the insert
            // first (between the exists() check above and this create()) —
            // the message is already recorded, nothing else to do. $stored
            // stays null: that concurrent request already owns any AI
            // Agent dispatch for it, not this one — same shape as
            // MessengerWebhookController::handleEvent()'s identical
            // try/catch around its own create().
        }

        // Phase 9 — an image with no caption is now dispatchable too (real
        // vision understanding, see AiAgentService/
        // ProcessWhatsAppAiAgentMessage::resolveImageUrl()), not just plain
        // text. Phase 10 — same for a voice message
        // (ProcessWhatsAppAiAgentMessage::transcribeAndPersist() converts
        // it to text before anything else runs). Every other type
        // (video/document/location/...) still needs real caption text to
        // dispatch at all — those stay text-only placeholders in history.
        $dispatchable = $type === 'text' ? (bool) $content['message_text'] : in_array($type, ['image', 'audio'], true);

        if ($stored && $dispatchable) {
            try {
                $this->maybeDispatchAiAgent($owner->tenant_id, $stored);
            } catch (\Throwable $e) {
                // Additive convenience, must never take the webhook down —
                // the message is already safely recorded regardless of
                // what happens here. Same posture as
                // MessengerWebhookController::handleEvent()'s identical
                // try/catch around maybeDispatchAiAgent().
                Log::warning('WhatsApp webhook: AI agent dispatch failed.', [
                    'tenant_id' => $owner->tenant_id,
                    'wa_id' => $waId,
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * AI Customer Support Agent (WhatsApp channel) — purely additive on
     * top of the message-storage flow above, never replacing any of it.
     * Only ever reached for a genuine inbound text message that was just
     * stored fresh (not a duplicate-delivery race, see the caller): the
     * Cloud API's webhook design itself is what prevents any loop risk
     * here — unlike Messenger, WhatsApp never echoes the business's own
     * outbound sends back through this 'messages' array (only through a
     * separate 'statuses' event, routed to handleStatus() instead), and
     * WhatsAppSendService — the only thing that ever creates an outbound
     * whatsapp_messages row — is never reached from this controller at
     * all. See ProcessWhatsAppAiAgentMessage::process()'s own
     * direction==='in' re-check for the belt-and-suspenders layer anyway.
     *
     * Gated by THREE independent checks, mirroring
     * MessengerWebhookController::maybeDispatchAiAgent() exactly:
     *  - ai_agent_enabled: the tenant's master AI switch, shared with
     *    Messenger/the panel chat.
     *  - whatsapp_ai_auto_reply_enabled: WhatsApp-specific. A tenant can
     *    have the master switch on while leaving WhatsApp auto-reply off
     *    — inbound messages still arrive and sit in the inbox exactly as
     *    before, just without an automatic AI reply.
     *  - AiCreditService::hasCredit(): checked here (not just inside the
     *    job) purely as an optimization — skips queuing a job, and
     *    writing the 'pending' tracking row for it, that would only
     *    immediately no-op. The job re-checks all three again itself
     *    (defense in depth against a race between this check and a worker
     *    picking the job up) — see ProcessWhatsAppAiAgentMessage::process().
     *
     * Everything here is synchronous but cheap: two settings lookups, one
     * balance read, one insert. All AI processing — building context,
     * calling OpenAI, sending the reply — happens inside the queued
     * ProcessWhatsAppAiAgentMessage job, never in this webhook request.
     */
    protected function maybeDispatchAiAgent(int $tenantId, WhatsAppMessage $message): void
    {
        if (! AiWhatsAppMessageJob::tablesReady()) {
            return; // database/sql/chunk35.sql not imported on this environment yet
        }

        if (! $this->isAiAgentEnabled($tenantId) || ! $this->isWhatsAppAutoReplyEnabled($tenantId)) {
            return;
        }

        // Phase 14 — purely an optimization, same reasoning as the credit
        // check below: skips queuing a job that would only immediately
        // no-op. The job re-checks this itself too — see
        // ProcessWhatsAppAiAgentMessage::process() and Tenant::isAiPaused()'s
        // docblock.
        if (Tenant::aiPauseColumnsReady() && Tenant::withoutGlobalScopes()->where('id', $tenantId)->value('ai_paused_at') !== null) {
            return;
        }

        if (! app(AiCreditService::class)->hasCredit($tenantId)) {
            return;
        }

        // Phase 13 — purely an optimization, same reasoning as the credit
        // check above: skips queuing a job (and writing its 'pending'
        // tracking row) that would only immediately no-op. The job
        // re-checks this itself too — see ProcessWhatsAppAiAgentMessage::process().
        if (app(AiHandoffService::class)->isActive($tenantId, 'whatsapp', $message->wa_id)) {
            return;
        }

        // Part 12/13 — mirrors MessengerWebhookController::
        // maybeDispatchAiAgent()'s coalescing setup one-for-one, using
        // this channel's own verified wa_id as the conversation key.
        $coalescingReady = AiWhatsAppMessageJob::conversationKeyColumnReady();

        $jobRow = [
            'tenant_id' => $tenantId,
            'whatsapp_message_id' => $message->id,
            'status' => 'pending',
        ];

        if ($coalescingReady) {
            $jobRow['conversation_key'] = $message->wa_id;
        }

        AiWhatsAppMessageJob::withoutGlobalScopes()->create($jobRow);

        if ($coalescingReady) {
            ProcessWhatsAppAiAgentMessage::dispatch($tenantId, $message->id)
                ->delay(now()->addSeconds((int) config('ai.message_coalesce_debounce_seconds', 6)));
        } else {
            ProcessWhatsAppAiAgentMessage::dispatch($tenantId, $message->id);
        }
    }

    /**
     * The AI Agent master ON/OFF toggle reuses the existing generic
     * store_settings table (key='ai_agent_enabled') — shared with
     * Messenger/the panel chat, same read as
     * MessengerWebhookController::isAiAgentEnabled(). No row for a
     * tenant means disabled.
     */
    protected function isAiAgentEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'ai_agent_enabled')
            ->value('value') === '1';
    }

    /**
     * WhatsApp-channel-specific toggle, independent of the master switch
     * above — see maybeDispatchAiAgent()'s docblock. Same store_settings/
     * "no row = disabled" shape as isAiAgentEnabled().
     */
    protected function isWhatsAppAutoReplyEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'whatsapp_ai_auto_reply_enabled')
            ->value('value') === '1';
    }

    /**
     * @return array{message_text: ?string, attachment_type: ?string, attachment_name: ?string}
     */
    protected function extractContent(string $type, array $message): array
    {
        return match ($type) {
            'text' => [
                'message_text' => $message['text']['body'] ?? null,
                'attachment_type' => null,
                'attachment_name' => null,
            ],
            'image', 'video', 'audio', 'sticker' => [
                // audio/sticker never carry a caption per Meta's schema —
                // ?? null makes that a no-op rather than a special case.
                'message_text' => $message[$type]['caption'] ?? null,
                'attachment_type' => $type,
                'attachment_name' => null,
            ],
            'document' => [
                'message_text' => $message['document']['caption'] ?? null,
                'attachment_type' => 'document',
                'attachment_name' => $message['document']['filename'] ?? null,
            ],
            'interactive' => [
                // button_reply/list_reply are mutually exclusive per Meta's
                // schema — whichever is present carries the customer's
                // actual selection.
                'message_text' => $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? null,
                'attachment_type' => null,
                'attachment_name' => null,
            ],
            'button' => [
                // A quick-reply tap on an outbound template's button —
                // Meta's payload calls the label "text", not "title".
                'message_text' => $message['button']['text'] ?? null,
                'attachment_type' => null,
                'attachment_name' => null,
            ],
            'contacts' => [
                // No dedicated contact-card columns in the approved Phase 1
                // schema, but "extract relevant contact information where
                // practical" doesn't require one — a readable name summary
                // fits the existing generic message_text column fine, full
                // vCard detail (phones/emails/etc.) stays in raw_payload.
                'message_text' => collect($message['contacts'] ?? [])
                    ->pluck('name.formatted_name')->filter()->implode(', ') ?: null,
                'attachment_type' => null,
                'attachment_name' => null,
            ],
            // location, and anything Meta adds later (reaction/order/
            // system/unsupported/unknown types) — location's lat/long has
            // no dedicated column per the approved Phase 1 schema ("persist
            // the relevant location data IF the approved schema supports
            // it" — it doesn't), so message_text stays null there and
            // raw_payload (already captured by the caller) is the full
            // record. Must never throw just because the type isn't one of
            // the cases above.
            default => [
                'message_text' => null,
                'attachment_type' => null,
                'attachment_name' => null,
            ],
        };
    }

    /**
     * Updates delivery_status only — never the Messenger-style triage
     * `status` column (new/contacted/converted/ignored), per explicit
     * instruction: no existing business rule ties WhatsApp delivery
     * receipts to conversation triage state.
     *
     * A status event for a wamid we have no message row for is logged and
     * dropped, not synthesized into a fake message — this webhook only
     * ever creates a row from an actual inbound message
     * (handleIncomingMessage()) or updates one that already exists here.
     */
    protected function handleStatus(array $status, object $owner): void
    {
        $wamid = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if (! $wamid || ! isset(self::STATUS_RANK[$newStatus])) {
            return;
        }

        $message = WhatsAppMessage::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->where('wamid', $wamid)
            ->first();

        if (! $message) {
            Log::info('WhatsApp webhook: status event for an unknown wamid, ignoring.', [
                'tenant_id' => $owner->tenant_id,
                'wamid' => $wamid,
                'status' => $newStatus,
            ]);

            return;
        }

        // WhatsApp doesn't guarantee status callback ordering (a delayed
        // "delivered" retry arriving after "read" was already recorded must
        // not regress the row backwards). Equal rank is allowed through so
        // a genuine retry of the same status is a harmless no-op update.
        $currentRank = self::STATUS_RANK[$message->delivery_status] ?? 0;

        if (self::STATUS_RANK[$newStatus] < $currentRank) {
            return;
        }

        $updates = ['delivery_status' => $newStatus];

        if ($newStatus === 'failed') {
            $error = $status['errors'][0] ?? [];
            $updates['error_code'] = $error['code'] ?? null;
            $updates['error_message'] = $error['title'] ?? ($error['message'] ?? null);
        }

        $message->update($updates);
    }

    /**
     * SQLite (used in this project's test suite — see CLAUDE.md, the real
     * schema is MySQL-only raw SQL) reports unique-constraint violations
     * under the same generic SQLSTATE 23000 driver code MySQL does, so the
     * plain string check MessengerWebhookController uses is reused as-is
     * rather than special-cased.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    /**
     * Verifies Meta's HMAC-SHA256 signature over the raw request body,
     * identical logic to MessengerWebhookController::hasValidSignature() —
     * same Meta App secret, same header, same fail-closed posture on a
     * missing secret. Kept as its own copy rather than extracted into a
     * shared trait/base class: two call sites is not yet a pattern, and the
     * instruction for this phase is "mirror proven patterns, don't share
     * plumbing that isn't asked for."
     */
    protected function hasValidSignature(Request $request): bool
    {
        $secret = config('whatsapp.app_secret');

        if (! $secret) {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
