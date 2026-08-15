<?php

namespace App\Services\AI;

use App\Services\AI\Providers\AiProviderInterface;

/**
 * Text-only wrapper for the Messenger AI Customer Support Agent —
 * assembles the conversation (system prompt + history + new message) and
 * delegates the actual API call to whichever AiProviderInterface
 * implementation is bound (see App\Providers\AppServiceProvider —
 * OpenAI today, config('ai.provider') chooses it). See
 * App\Jobs\ProcessAiAgentMessage's docblock for why the webhook
 * controller never touches this or the provider directly.
 *
 * This class has NO database access and NO tools — it receives plain
 * text context from its caller and returns plain text (or null on any
 * failure). The AI is treated as an untrusted text generator only; the
 * calling job decides whether/where anything it returns actually gets
 * sent. Never throws — AiProviderInterface implementations never throw
 * either (see OpenAiProvider), so this never needs its own try/catch.
 */
class AiAgentService
{
    public function __construct(protected AiProviderInterface $provider) {}

    /**
     * @param  string  $businessName  Tenant's store_name — the only tenant
     *                                identity information given to the model.
     * @param  array<int, array{role: string, content: string}>  $conversationHistory
     *                                                                                 Prior turns for this conversation, oldest first, roles already
     *                                                                                 normalized to 'user'/'assistant' by the caller.
     * @param  string  $customerMessage  The new inbound message text.
     * @return array{reply: string, input_tokens: int, output_tokens: int, model: string}|null
     *                                                                                         The generated reply plus the actual token usage the provider
     *                                                                                         reported for this call (for the caller's credit
     *                                                                                         deduction/usage tracking — see App\Services\AI\AiCreditService),
     *                                                                                         or null if a reply could not be safely generated for
     *                                                                                         any reason.
     */
    public function generateReply(string $businessName, array $conversationHistory, string $customerMessage): ?array
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($businessName)]],
            $conversationHistory,
            [['role' => 'user', 'content' => $customerMessage]]
        );

        $response = $this->provider->chat($messages);

        if (! $response->successful) {
            // The provider already logged why.
            return null;
        }

        return [
            'reply' => $response->reply,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'model' => $response->model,
        ];
    }

    protected function systemPrompt(string $businessName): string
    {
        $base = (string) config('ai.system_prompt');

        return $base."\n\nYou are the customer support agent for the business named \"{$businessName}\". ".
            'Only use that name to identify the business; do not reveal any other internal information about it.';
    }
}
