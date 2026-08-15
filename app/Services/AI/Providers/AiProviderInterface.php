<?php

namespace App\Services\AI\Providers;

/**
 * Contract every AI provider implementation must satisfy —
 * AiAgentService/AiChatService depend on this interface, never on a
 * concrete provider class, so swapping/adding a provider (Anthropic,
 * Groq, a self-hosted model, etc.) never requires touching either or
 * anything that calls them. See App\Providers\AppServiceProvider::register()
 * for how the concrete implementation is chosen (config('ai.provider'),
 * same match()-on-config-driver shape already used for DomainDriver).
 */
interface AiProviderInterface
{
    /**
     * @param  array<int, array<string, mixed>>  $messages  Full chat history in
     *                                                      OpenAI-style role/content shape, oldest first, system prompt already
     *                                                      included as the first element — the provider sends exactly this,
     *                                                      unmodified. May also contain 'assistant' messages with a 'tool_calls'
     *                                                      key and 'tool' messages with a 'tool_call_id' key, when a caller is
     *                                                      mid-way through a tool-calling round trip (see AiChatService).
     * @param  array<int, array{type: string, function: array{name: string, description: string, parameters: array}}>  $tools
     *                                                                                                                         OpenAI function-calling tools schema (see
     *                                                                                                                         App\Services\AI\Tools\AiToolRegistry::toOpenAiSchema()). Empty
     *                                                                                                                         array (the default) means no tool-calling is offered at all —
     *                                                                                                                         the only shape AiAgentService (the Messenger flow) ever passes,
     *                                                                                                                         since it never involves tools.
     */
    public function chat(array $messages, array $tools = []): AiProviderResponse;
}
