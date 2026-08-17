<?php

namespace App\Services\WhatsApp;

use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outgoing WhatsApp Cloud API sends, plus persistence of the resulting
 * whatsapp_messages row — the WhatsApp analogue of MessengerInboxController
 * ::reply() + MessengerApi combined into one boundary, per this phase's
 * explicit instruction to keep all Graph API HTTP communication inside the
 * service rather than split across a controller and a thin wrapper the way
 * Messenger happens to be structured. Deliberately NOT calling into
 * MessengerApi or anything Messenger-owns — same platform, independent
 * implementation, so a future change to either never has to reason about
 * the other.
 *
 * Every public method takes a resolved Tenant model, never a tenant_id (or
 * any other identifier) read from a request — the caller (a future Phase 4
 * controller) is expected to pass app('currentTenant'), the same
 * already-authenticated/resolved object every other tenant-scoped
 * controller in this codebase uses. This service resolves that tenant's
 * connected WhatsApp phone number itself, with an explicit tenant_id filter
 * (withoutGlobalScopes() + ->where('tenant_id', $tenant->id)) rather than
 * relying on BelongsToTenant's global scope/app('currentTenant') binding —
 * same defense-in-depth reasoning as FacebookConnectController::disconnect()'s
 * docblock: this service must give the right answer regardless of whether
 * the global scope happens to be bound to a matching tenant at call time.
 */
class WhatsAppSendService
{
    /** Media types the Cloud API's /caption field actually accepts — audio and sticker do not, same constraint the inbound webhook already encodes in its extractContent(). */
    protected const CAPTION_SUPPORTED_TYPES = ['image', 'document', 'video'];

    protected const MEDIA_TYPES = ['image', 'document', 'audio', 'video', 'sticker'];

    protected string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/'.config('whatsapp.graph_version');
    }

    /**
     * @param  string  $sentBy  'human' (default — every existing caller, e.g.
     *                          Tenant\WhatsAppInboxController::reply(), needs no change) or 'ai' —
     *                          see database/sql/chunk37.sql and WhatsAppMessage::sentByColumnReady().
     *                          Only App\Jobs\ProcessWhatsAppAiAgentMessage ever passes 'ai'.
     */
    public function sendText(Tenant $tenant, string $to, string $text, string $sentBy = 'human'): WhatsAppSendResult
    {
        $phoneNumber = $this->resolvePhoneNumber($tenant);

        if (! $phoneNumber) {
            return WhatsAppSendResult::failure(null, 'No active WhatsApp phone number connected for this tenant.');
        }

        return $this->send(
            $phoneNumber,
            ['type' => 'text', 'text' => ['body' => $text]],
            $to,
            [
                'message_type' => 'text',
                'message_text' => $text,
            ],
            $sentBy
        );
    }

    /**
     * $link must already be a publicly reachable URL Meta can fetch — same
     * "our own durable URL, not a direct upload" convention
     * MessengerApi::sendAttachment()/MessengerWebhookController::
     * rehostAttachment() use for Messenger, just via WhatsApp's own payload
     * shape ({type}: {link, caption?, filename?}) rather than Messenger's
     * generic attachment.payload.url. No media-upload-endpoint support in
     * this phase — link-based sending only.
     */
    public function sendMedia(Tenant $tenant, string $to, string $type, string $link, ?string $caption = null, ?string $filename = null, string $sentBy = 'human'): WhatsAppSendResult
    {
        if (! in_array($type, self::MEDIA_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported WhatsApp media type: {$type}");
        }

        $phoneNumber = $this->resolvePhoneNumber($tenant);

        if (! $phoneNumber) {
            return WhatsAppSendResult::failure(null, 'No active WhatsApp phone number connected for this tenant.');
        }

        $captionApplies = $caption && in_array($type, self::CAPTION_SUPPORTED_TYPES, true);
        $filenameApplies = $filename && $type === 'document';

        $media = array_filter([
            'link' => $link,
            'caption' => $captionApplies ? $caption : null,
            'filename' => $filenameApplies ? $filename : null,
        ], fn ($v) => $v !== null);

        return $this->send(
            $phoneNumber,
            [$type => $media, 'type' => $type],
            $to,
            [
                'message_type' => $type,
                'message_text' => $captionApplies ? $caption : null,
                'attachment_url' => $link,
                'attachment_type' => $type,
                'attachment_name' => $filenameApplies ? $filename : null,
            ],
            $sentBy
        );
    }

    protected function resolvePhoneNumber(Tenant $tenant): ?WhatsAppPhoneNumber
    {
        if (! WhatsAppPhoneNumber::tablesReady()) {
            return null;
        }

        return WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', 1)
            ->where('status', 'active')
            ->with('businessAccount')
            ->first();
    }

    /**
     * @param  array  $body  The Cloud API message-type-specific body (everything after messaging_product/to).
     * @param  array  $baseAttributes  message_type/message_text/attachment_* — merged with the tenant/phone/wa_id/direction/status fields common to every send.
     */
    protected function send(WhatsAppPhoneNumber $phoneNumber, array $body, string $to, array $baseAttributes, string $sentBy = 'human'): WhatsAppSendResult
    {
        $messageAttributes = array_merge([
            'tenant_id' => $phoneNumber->tenant_id,
            'whatsapp_phone_number_id' => $phoneNumber->id,
            'wa_id' => $to,
            'direction' => 'out',
            // Same "an outbound message means staff engaged with this
            // conversation" rule Messenger applies (MessengerInboxController
            // ::reply() sets 'contacted' on every reply it sends) — kept
            // regardless of whether THIS particular send technically
            // succeeds, since delivery_status (not status) is what actually
            // reflects the technical outcome. See chunk26.sql's rationale
            // for why these two columns are deliberately separate.
            'status' => 'contacted',
        ], $baseAttributes);

        // Gated exactly like MessengerMessage's sent_by write — see
        // WhatsAppMessage::sentByColumnReady()'s docblock. Unconditionally
        // adding this key would throw "Unknown column" on any tenant
        // database that hasn't imported chunk37.sql yet.
        if (WhatsAppMessage::sentByColumnReady()) {
            $messageAttributes['sent_by'] = $sentBy;
        }

        $token = $phoneNumber->businessAccount?->user_access_token;

        if (! $token) {
            return WhatsAppSendResult::failure(null, 'WhatsApp Business Account is connected but has no usable access token.');
        }

        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->post("{$this->base}/{$phoneNumber->phone_number_id}/messages", array_merge([
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                ], $body));
        } catch (ConnectionException $e) {
            // Deliberately not logging $e->getMessage() here or anywhere
            // else in this method — Http::withToken() puts the access token
            // in the Authorization header, never the URL, but a connection
            // exception's message can still echo request details depending
            // on the underlying Guzzle handler. Logging only structured,
            // known-safe fields (ids, error codes) throughout this class is
            // the same posture FacebookConnectController::disconnect() uses
            // for the same reason.
            Log::error('WhatsApp send: connection failure calling the Cloud API.', [
                'tenant_id' => $phoneNumber->tenant_id,
                'phone_number_id' => $phoneNumber->phone_number_id,
            ]);

            return WhatsAppSendResult::failure(null, 'Could not connect to the WhatsApp Cloud API.');
        }

        // Meta can return an `error` object embedded in a 200 OK body for
        // some failure modes, not only via a non-2xx HTTP status — checking
        // successful() alone would let that case through as a false
        // success, same reasoning as FacebookOAuthService::throwIfFailed().
        $error = $response->json('error');

        if (! $response->successful() || $error) {
            return $this->recordFailure($phoneNumber, $messageAttributes, (array) ($error ?: []));
        }

        $wamid = $response->json('messages.0.id');

        $message = WhatsAppMessage::withoutGlobalScopes()->create(array_merge($messageAttributes, [
            'wamid' => $wamid,
            'delivery_status' => 'sent',
        ]));

        return WhatsAppSendResult::success($message);
    }

    /**
     * Persists the failed attempt (using the error_code/error_message
     * columns chunk26.sql added specifically for this) rather than silently
     * dropping it the way Messenger's reply() does on failure — the schema
     * already supports recording it, so a future inbox UI can show the
     * customer-visible thread accurately ("this message failed to send")
     * instead of the attempt vanishing with no trace. wamid stays NULL:
     * Meta never assigned one, and NULL is safe under the UNIQUE(wamid)
     * index (multiple NULLs allowed, proven in Phase 1's schema tests).
     *
     * Also flips the phone number to needs_reconnect on Meta's standard
     * invalid/expired token error code (190) — same signal
     * FacebookConnectController::handleGraphFailure() acts on for Pages,
     * applied at the same granularity Phase 1's schema already models
     * (per-phone-number status, not a whole-WABA flag).
     */
    protected function recordFailure(WhatsAppPhoneNumber $phoneNumber, array $messageAttributes, array $error): WhatsAppSendResult
    {
        $errorCode = isset($error['code']) ? (string) $error['code'] : null;
        $errorMessage = $error['message'] ?? 'WhatsApp Cloud API request failed.';

        Log::error('WhatsApp send: Cloud API rejected the request.', [
            'tenant_id' => $phoneNumber->tenant_id,
            'phone_number_id' => $phoneNumber->phone_number_id,
            'error_code' => $errorCode,
            'error_type' => $error['type'] ?? null,
        ]);

        if ((int) ($error['code'] ?? 0) === 190) {
            $phoneNumber->update(['status' => 'needs_reconnect']);
        }

        $message = WhatsAppMessage::withoutGlobalScopes()->create(array_merge($messageAttributes, [
            'wamid' => null,
            'delivery_status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]));

        return WhatsAppSendResult::failure($errorCode, $errorMessage, $message);
    }
}
