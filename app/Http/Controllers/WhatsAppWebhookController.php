<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
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

        try {
            WhatsAppMessage::withoutGlobalScopes()->create($attributes);
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }
            // Race: a concurrent retry of this same wamid won the insert
            // first (between the exists() check above and this create()) —
            // the message is already recorded, nothing else to do. Same
            // shape as MessengerWebhookController::handleEvent()'s
            // try/catch around its own create().
        }
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
