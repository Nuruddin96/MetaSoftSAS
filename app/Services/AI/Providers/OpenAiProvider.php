<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place OpenAI-specific HTTP/API-shape logic lives. Extracted
 * verbatim out of AiAgentService (Phase 1/2) when the provider
 * abstraction was introduced; extended with tool-calling support (Phase
 * 4) purely by parsing `choices.0.message.tool_calls` when present — the
 * request/response shape for a plain, tools-less call (what
 * AiAgentService/Messenger still sends) is completely unchanged.
 *
 * Never throws — every failure mode (missing config, network error,
 * OpenAI error response, empty/malformed reply) is caught here and
 * turned into AiProviderResponse::failure() plus a safe log line, so a
 * caller can never crash from it.
 */
class OpenAiProvider implements AiProviderInterface
{
    public function chat(array $messages, array $tools = []): AiProviderResponse
    {
        $apiKey = config('ai.openai_api_key');

        if (! $apiKey) {
            Log::warning('AI provider (openai): OPENAI_API_KEY is not configured — cannot generate a reply.');

            return AiProviderResponse::failure();
        }

        $payload = [
            'model' => config('ai.openai_model'),
            'messages' => $messages,
            // 'max_tokens' is rejected outright (400 unsupported_parameter)
            // by newer models — gpt-5-mini among them — which require
            // 'max_completion_tokens' instead. OpenAI accepts
            // max_completion_tokens across the whole current model lineup
            // (including pre-reasoning models), so this is a single,
            // unconditional switch rather than a per-model branch.
            'max_completion_tokens' => (int) config('ai.max_tokens', 500),
        ];

        if ($tools) {
            $payload['tools'] = $tools;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('ai.timeout_seconds', 20))
                ->post(rtrim(config('ai.openai_base_url'), '/').'/chat/completions', $payload);
        } catch (\Throwable $e) {
            // Transport-level failure (DNS, timeout, connection refused).
            // Never log $e->getMessage() — same caution this codebase
            // already applies to Graph API exceptions, since a transport
            // exception message can echo request details.
            Log::warning('AI provider (openai): request failed at the transport level.', [
                'exception' => get_class($e),
            ]);

            return AiProviderResponse::failure();
        }

        if ($response->failed()) {
            // error.type/error.code/error.param are short, machine-readable
            // enum-like strings (e.g. "invalid_request_error",
            // "unsupported_parameter", "max_tokens") — never user content,
            // never a credential, always safe to log. error.message is
            // NOT always safe: OpenAI's invalid-API-key error (HTTP 401,
            // error.code=invalid_api_key) echoes back a partial key
            // ("Incorrect API key provided: sk-...xyz"), so it's only
            // logged for non-401 responses, where OpenAI's error text is
            // just a plain description of what was wrong with the request
            // (bad parameter, bad model name, etc.), never the key.
            $context = [
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
                'error_code' => $response->json('error.code'),
                'error_param' => $response->json('error.param'),
            ];

            if ($response->status() !== 401) {
                $context['error_message'] = $response->json('error.message');
            }

            Log::warning('AI provider (openai): API returned an error response.', $context);

            return AiProviderResponse::failure();
        }

        $reply = $response->json('choices.0.message.content');
        $hasReply = is_string($reply) && trim($reply) !== '';

        $toolCalls = array_map(fn (array $call) => [
            'id' => $call['id'] ?? null,
            'name' => $call['function']['name'] ?? null,
            // OpenAI sends function arguments as a JSON-encoded string,
            // not a nested object — decoded here so every caller works
            // with plain arrays, same as everywhere else in this codebase.
            // Malformed JSON degrades to an empty array rather than
            // throwing — a tool receiving no usable arguments is a normal,
            // handleable case (see AiTool::handle()'s docblock), not a
            // reason to crash the whole response.
            'arguments' => json_decode($call['function']['arguments'] ?? '{}', true) ?? [],
        ], $response->json('choices.0.message.tool_calls') ?? []);

        if (! $hasReply && ! $toolCalls) {
            Log::warning('AI provider (openai): response contained no usable reply text or tool calls.');

            return AiProviderResponse::failure();
        }

        return AiProviderResponse::success(
            $hasReply ? trim($reply) : null,
            (int) ($response->json('usage.prompt_tokens') ?? 0),
            (int) ($response->json('usage.completion_tokens') ?? 0),
            (string) config('ai.openai_model'),
            $toolCalls,
        );
    }
}
