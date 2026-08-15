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
     * @param  string|null  $styleExamples  Compact style profile + "Customer: ... /
     *                                      Reply: ..." pairs built from this tenant's own real,
     *                                      human-written Messenger replies (see App\Services\AI\
     *                                      AiConversationStyleService) — null/empty when none are
     *                                      available yet, in which case the prompt falls back to the
     *                                      general style rules alone. This class has no database
     *                                      access itself (see class docblock), so the caller is
     *                                      responsible for fetching this, same as $conversationHistory.
     * @param  string|null  $customerName  The customer's display name, when known (e.g.
     *                                     MessengerMessage::customer_name) — used only so the model can make a
     *                                     conservative, optional gender/address-term judgment per
     *                                     config('ai.system_prompt')'s addressing rules; never required.
     * @return array{reply: string, input_tokens: int, output_tokens: int, model: string}|null
     *                                                                                         The generated reply plus the actual token usage the provider
     *                                                                                         reported for this call (for the caller's credit
     *                                                                                         deduction/usage tracking — see App\Services\AI\AiCreditService),
     *                                                                                         or null if a reply could not be safely generated for
     *                                                                                         any reason.
     */
    public function generateReply(string $businessName, array $conversationHistory, string $customerMessage, ?string $styleExamples = null, ?string $customerName = null): ?array
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($businessName, $styleExamples, $customerName)]],
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

    protected function systemPrompt(string $businessName, ?string $styleExamples, ?string $customerName = null): string
    {
        $base = (string) config('ai.system_prompt');

        $prompt = $base."\n\nYou are the customer support agent for the business named \"{$businessName}\". ".
            'Only use that name to identify the business; do not reveal any other internal information about it.';

        if ($customerName) {
            // Purely informational — the addressing rules above (never
            // guess from an ambiguous name, never use every message)
            // already govern how/whether this gets used at all.
            $prompt .= "\n\nThe customer's name, if useful: \"{$customerName}\".";
        }

        if ($styleExamples) {
            // Priority is explicit: these examples are the strongest guide
            // to TONE (wording, brevity, greeting style, emoji use) — never
            // a source of current facts. A stale price/detail that happens
            // to appear in an old example must never be repeated as if it
            // were still true; the rules above (never invent/never assume
            // stale facts are current) already cover that, this just makes
            // the precedence explicit for the model.
            $prompt .= "\n\nBelow are real recent examples of how this business's own staff actually replied to customers — match their exact tone, wording, brevity, and language style; this is a stronger guide than the general rules above for HOW to sound. These are for STYLE only — never treat any price, availability, or other fact mentioned in them as still true now; always rely on the current conversation and known business information for facts, not these examples:\n\n{$styleExamples}";
        }

        return $prompt;
    }
}
