<?php

namespace App\Services\AI\Providers;

/**
 * The abstraction every AiProviderInterface implementation returns — a
 * caller (AiAgentService) never has to know whether the underlying call
 * succeeded via HTTP 200 or failed via a 4xx/5xx/transport exception/
 * empty-reply, it only ever branches on ->successful. Same "typed result,
 * not a raw API response shape" pattern already established by
 * App\Services\WhatsApp\WhatsAppSendResult.
 */
final class AiProviderResponse
{
    /**
     * @param  array<int, array{id: string, name: string, arguments: array}>  $toolCalls
     *                                                                                    Empty for a plain text-only reply (the only shape
     *                                                                                    AiAgentService/the Messenger flow ever produces, since
     *                                                                                    it never passes a $tools schema to chat()). Non-empty
     *                                                                                    means the model chose to call one or more tools instead
     *                                                                                    of (or before) replying — see AiChatService, the only
     *                                                                                    caller that passes tools today.
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $reply,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?string $model,
        public readonly array $toolCalls,
    ) {}

    /**
     * $reply may be null when $toolCalls is non-empty — OpenAI returns no
     * content on a pure tool-call turn, which is a normal, successful
     * outcome, not a failure.
     */
    public static function success(?string $reply, int $inputTokens, int $outputTokens, string $model, array $toolCalls = []): self
    {
        return new self(true, $reply, $inputTokens, $outputTokens, $model, $toolCalls);
    }

    /**
     * Deliberately carries no error detail (code/message) — every
     * provider implementation is responsible for logging its own failure
     * reason safely (see OpenAiProvider's "never log raw error text"
     * comments) before returning this. Callers only ever need to know
     * "did it work", never why, since nothing downstream (AiAgentService,
     * ProcessAiAgentMessage) does anything different based on the failure
     * reason.
     */
    public static function failure(): self
    {
        return new self(false, null, 0, 0, null, []);
    }
}
