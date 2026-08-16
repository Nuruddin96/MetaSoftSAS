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
     * Phase 12 — the Send API's sender_action shape, unchanged for as long
     * as the Messenger Platform has existed. Shows the "Business is
     * typing..." indicator to the customer; Meta clears it automatically
     * after ~20s or the moment an actual message is sent, so there is no
     * corresponding typing_off call to make. Purely cosmetic — see
     * App\Jobs\ProcessAiAgentMessage::humanDelay()'s docblock for why this
     * exists and why a failure here must never block the actual reply.
     */
    public function sendTypingOn(string $psid, string $pageAccessToken): array
    {
        return Http::post("{$this->base}/me/messages?access_token={$pageAccessToken}", [
            'recipient' => ['id' => $psid],
            'sender_action' => 'typing_on',
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
