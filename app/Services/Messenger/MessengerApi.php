<?php

namespace App\Services\Messenger;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Thin wrapper around Meta's Graph API for Messenger. */
class MessengerApi
{
    protected string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/'.config('facebook.graph_version');
    }

    /**
     * profile_pic added alongside the pre-existing first_name/last_name in
     * the same request/same call sites — not a second Graph call, just a
     * richer response from the one already being made (see
     * FacebookMessengerCustomerService, the identity system's canonical
     * caller of this method).
     *
     * Returns the raw Response rather than a plain array/null — Meta always
     * sends a JSON body, success or error, as long as the HTTP call itself
     * completed, and callers that need to log/inspect a failure (see
     * FacebookMessengerCustomerService::syncCustomerProfile()) need the
     * status code and error payload, not just a boolean "it failed." Still
     * throws on a genuine transport-level failure (DNS/timeout/TLS), same
     * as before — callers already wrap this in try/catch for that.
     */
    public function getProfile(string $psid, string $pageAccessToken): Response
    {
        return Http::get("{$this->base}/{$psid}", [
            'fields' => 'first_name,last_name,profile_pic',
            'access_token' => $pageAccessToken,
        ]);
    }

    public function sendMessage(string $psid, string $text, string $pageAccessToken): array
    {
        return Http::post("{$this->base}/me/messages?access_token={$pageAccessToken}", [
            'recipient' => ['id' => $psid],
            'message' => ['text' => $text],
            'messaging_type' => 'RESPONSE',
        ])->json() ?? [];
    }

    /**
     * Sends a media attachment by URL (our own re-hosted public URL, not a
     * direct file upload — matches how MessengerWebhookController re-hosts
     * inbound attachments, so both directions use the same "our durable
     * URL" convention). $type is one of Meta's attachment types the Send
     * API accepts: image, audio, video, file.
     */
    public function sendAttachment(string $psid, string $url, string $type, string $pageAccessToken): array
    {
        return Http::post("{$this->base}/me/messages?access_token={$pageAccessToken}", [
            'recipient' => ['id' => $psid],
            'message' => [
                'attachment' => [
                    'type' => $type,
                    'payload' => ['url' => $url, 'is_reusable' => true],
                ],
            ],
            'messaging_type' => 'RESPONSE',
        ])->json() ?? [];
    }
}
