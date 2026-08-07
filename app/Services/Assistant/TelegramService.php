<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Http;

class TelegramService
{
    protected string $base;

    public function __construct()
    {
        $this->base = 'https://api.telegram.org/bot' . config('assistant.telegram_bot_token');
    }

    public function sendMessage(string $chatId, string $text): void
    {
        // Telegram messages are capped ~4096 chars; split long replies.
        foreach (str_split($text, 3800) as $chunk) {
            Http::post($this->base . '/sendMessage', [
                'chat_id'    => $chatId,
                'text'       => $chunk,
                'parse_mode' => 'Markdown',
            ]);
        }
    }

    public function sendTyping(string $chatId): void
    {
        Http::post($this->base . '/sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    public function setWebhook(string $url): array
    {
        return Http::post($this->base . '/setWebhook', ['url' => $url])->json() ?? [];
    }

    public function getWebhookInfo(): array
    {
        return Http::get($this->base . '/getWebhookInfo')->json() ?? [];
    }
}
