<?php

namespace App\Services\Messenger;

use Illuminate\Support\Facades\Http;

/** Thin wrapper around Meta's Graph API for Messenger. */
class MessengerApi
{
    protected string $base;

    public function __construct()
    {
        $this->base = 'https://graph.facebook.com/'.config('facebook.graph_version');
    }

    public function getProfile(string $psid, string $pageAccessToken): ?array
    {
        $response = Http::get("{$this->base}/{$psid}", [
            'fields' => 'first_name,last_name',
            'access_token' => $pageAccessToken,
        ]);

        return $response->successful() ? $response->json() : null;
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
