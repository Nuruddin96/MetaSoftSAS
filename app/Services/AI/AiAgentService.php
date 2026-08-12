<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1/2: text-only OpenAI wrapper for the Messenger AI Customer
 * Support Agent. Deliberately the only place OpenAI API logic lives —
 * see App\Jobs\ProcessAiAgentMessage's docblock for why the webhook
 * controller never touches this directly.
 *
 * This class has NO database access and NO tools — it receives plain
 * text context from its caller and returns plain text (or null on any
 * failure). The AI is treated as an untrusted text generator only; the
 * calling job decides whether/where anything it returns actually gets
 * sent. Never throws — every failure mode (missing config, network
 * error, OpenAI error response, empty/malformed reply) is caught here
 * and turned into a null return plus a safe log line, so a queued job
 * calling this can never crash from it.
 */
class AiAgentService
{
    /**
     * @param  string  $businessName  Tenant's store_name — the only tenant
     *                                identity information given to the model.
     * @param  array<int, array{role: string, content: string}>  $conversationHistory
     *                                                                                 Prior turns for this conversation, oldest first, roles already
     *                                                                                 normalized to 'user'/'assistant' by the caller.
     * @param  string  $customerMessage  The new inbound message text.
     * @return string|null The generated reply, or null if a reply could
     *                     not be safely generated for any reason.
     */
    public function generateReply(string $businessName, array $conversationHistory, string $customerMessage): ?string
    {
        $apiKey = config('ai.openai_api_key');

        if (! $apiKey) {
            Log::warning('AI agent: OPENAI_API_KEY is not configured — cannot generate a reply.');

            return null;
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($businessName)]],
            $conversationHistory,
            [['role' => 'user', 'content' => $customerMessage]]
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('ai.timeout_seconds', 20))
                ->post(rtrim(config('ai.openai_base_url'), '/').'/chat/completions', [
                    'model' => config('ai.openai_model'),
                    'messages' => $messages,
                    'max_tokens' => (int) config('ai.max_tokens', 500),
                ]);
        } catch (\Throwable $e) {
            // Transport-level failure (DNS, timeout, connection refused).
            // Never log $e->getMessage() — same caution this codebase
            // already applies to Graph API exceptions, since a transport
            // exception message can echo request details.
            Log::warning('AI agent: OpenAI request failed at the transport level.', [
                'exception' => get_class($e),
            ]);

            return null;
        }

        if ($response->failed()) {
            // Never log $response->json('error.message') — OpenAI's own
            // invalid-key error text echoes back a partial API key
            // ("Incorrect API key provided: sk-...xyz"), so only the
            // status/error type are safe to record.
            Log::warning('AI agent: OpenAI API returned an error response.', [
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
            ]);

            return null;
        }

        $reply = $response->json('choices.0.message.content');

        if (! is_string($reply) || trim($reply) === '') {
            Log::warning('AI agent: OpenAI response contained no usable reply text.');

            return null;
        }

        return trim($reply);
    }

    protected function systemPrompt(string $businessName): string
    {
        $base = (string) config('ai.system_prompt');

        return $base."\n\nYou are the customer support agent for the business named \"{$businessName}\". ".
            'Only use that name to identify the business; do not reveal any other internal information about it.';
    }
}
