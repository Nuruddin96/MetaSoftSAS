<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * On-demand inbound media retrieval — the piece WhatsAppWebhookController::
 * handleIncomingMessage() deliberately deferred ("No media download/re-
 * hosting in this phase... enough to fetch it later once that service
 * exists"). Fetches through the Cloud API on every request rather than
 * downloading once at webhook time and storing a copy: this codebase has no
 * confirmed background queue worker on shared hosting (see
 * MessengerInboxController/WhatsAppInboxController's polling-not-WebSockets
 * reasoning), so doing a network call inside the webhook request itself
 * would add latency/failure surface to something Meta expects to ack fast —
 * proxying at view time instead costs nothing there and needs no storage.
 *
 * Two-step Cloud API shape: GET /{media-id} returns a short-lived signed
 * `url` + `mime_type`; that url must then be fetched with the same bearer
 * token (Meta rejects an unauthenticated request to it). Both calls reuse
 * the exact bearer-header convention WhatsAppSendService already uses —
 * never a query-string token, and never logged.
 *
 * Caveat worth knowing operationally: Meta does not guarantee inbound media
 * stays retrievable forever (its retention window is Meta's, not
 * configurable here) — very old conversations may 404 even though the
 * message row and its metadata remain in the database permanently.
 */
class WhatsAppMediaService
{
    protected string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/'.config('whatsapp.graph_version');
    }

    /** @return array{body: string, mimeType: string}|null */
    public function fetch(string $mediaId, string $accessToken): ?array
    {
        try {
            $lookup = Http::timeout(10)->withToken($accessToken)->get("{$this->base}/{$mediaId}");

            if (! $lookup->successful() || $lookup->json('error')) {
                Log::warning('WhatsApp media: lookup failed.', [
                    'media_id' => $mediaId,
                    'error_code' => $lookup->json('error.code'),
                ]);

                return null;
            }

            $url = $lookup->json('url');
            $mimeType = $lookup->json('mime_type', 'application/octet-stream');

            if (! $url) {
                return null;
            }

            $file = Http::timeout(20)->withToken($accessToken)->get($url);

            if (! $file->successful()) {
                Log::warning('WhatsApp media: download failed.', [
                    'media_id' => $mediaId,
                    'status' => $file->status(),
                ]);

                return null;
            }

            return ['body' => $file->body(), 'mimeType' => $mimeType];
        } catch (ConnectionException $e) {
            // Same "never log $e->getMessage()" caution as WhatsAppSendService
            // — a connection exception's message can embed the request URL,
            // and the media lookup URL/download URL both carry the token or
            // a Meta-signed equivalent.
            Log::error('WhatsApp media: connection failure.', ['media_id' => $mediaId]);

            return null;
        }
    }
}
