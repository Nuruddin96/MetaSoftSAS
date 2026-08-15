<?php

namespace Tests\Feature\AiAgent;

use App\Services\AI\AiAgentService;
use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Providers\AiProviderResponse;
use Tests\TestCase;

/**
 * Covers AiAgentService in isolation, decoupled from OpenAI entirely —
 * the actual point of the Phase 2 provider abstraction. Swaps in a fake
 * AiProviderInterface implementation via the container (no Http::fake,
 * no OpenAI-shaped response needed) to prove AiAgentService only ever
 * depends on the interface, never on OpenAiProvider or any HTTP detail.
 * ProcessAiAgentMessageJobTest separately proves the real OpenAiProvider
 * wiring still works end-to-end through the actual bound implementation.
 */
class AiAgentServiceTest extends TestCase
{
    protected function bindFakeProvider(\Closure $chat): void
    {
        $this->app->bind(AiProviderInterface::class, fn () => new class($chat) implements AiProviderInterface
        {
            public function __construct(private \Closure $chat) {}

            public function chat(array $messages, array $tools = []): AiProviderResponse
            {
                return ($this->chat)($messages);
            }
        });
    }

    public function test_assembles_system_prompt_history_and_new_message_in_order(): void
    {
        config(['ai.system_prompt' => 'BASE PROMPT']);

        $seen = null;
        $this->bindFakeProvider(function (array $messages) use (&$seen) {
            $seen = $messages;

            return AiProviderResponse::success('ok', 1, 1, 'fake-model');
        });

        $history = [
            ['role' => 'user', 'content' => 'আগের মেসেজ'],
            ['role' => 'assistant', 'content' => 'আগের রিপ্লাই'],
        ];

        app(AiAgentService::class)->generateReply('Shop Basket', $history, 'নতুন মেসেজ');

        $this->assertNotNull($seen);
        $this->assertSame('system', $seen[0]['role']);
        $this->assertStringContainsString('BASE PROMPT', $seen[0]['content']);
        $this->assertStringContainsString('Shop Basket', $seen[0]['content']);
        $this->assertSame($history[0], $seen[1]);
        $this->assertSame($history[1], $seen[2]);
        $this->assertSame(['role' => 'user', 'content' => 'নতুন মেসেজ'], $seen[3]);
    }

    public function test_returns_reply_and_token_usage_from_a_successful_provider_response(): void
    {
        $this->bindFakeProvider(fn () => AiProviderResponse::success('দাম ৫০০ টাকা।', 42, 13, 'fake-model'));

        $result = app(AiAgentService::class)->generateReply('Shop Basket', [], 'দাম কত?');

        $this->assertSame([
            'reply' => 'দাম ৫০০ টাকা।',
            'input_tokens' => 42,
            'output_tokens' => 13,
            'model' => 'fake-model',
        ], $result);
    }

    public function test_returns_null_when_the_provider_reports_failure(): void
    {
        $this->bindFakeProvider(fn () => AiProviderResponse::failure());

        $result = app(AiAgentService::class)->generateReply('Shop Basket', [], 'দাম কত?');

        $this->assertNull($result);
    }
}
