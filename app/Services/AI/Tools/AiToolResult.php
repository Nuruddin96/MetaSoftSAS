<?php

namespace App\Services\AI\Tools;

/**
 * The abstraction AiToolRegistry::call() always returns — same "typed
 * result, not a raw exception/array" pattern already established by
 * App\Services\WhatsApp\WhatsAppSendResult and
 * App\Services\AI\Providers\AiProviderResponse. A caller (AI-mediated or
 * a direct deterministic call — see AiToolRegistry's docblock) only ever
 * branches on ->successful, never on catching an exception.
 */
final class AiToolResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly array $data,
        public readonly ?string $error,
    ) {}

    public static function success(array $data): self
    {
        return new self(true, $data, null);
    }

    /**
     * $error is a short, safe-to-show-the-AI reason ("Unknown tool",
     * "Tool execution failed") — never a raw exception message, which
     * could echo query/credential details. See
     * AiToolRegistry::call()'s catch block.
     */
    public static function failure(string $error): self
    {
        return new self(false, [], $error);
    }
}
