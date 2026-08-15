<?php

namespace App\Services\AI\Tools;

use Illuminate\Support\Facades\Log;

/**
 * The single execution entry point for every predefined AI tool — the
 * ONLY way any caller should invoke one, whether that caller is an
 * OpenAI tool-calling response being handled by App\Services\AI\AiChatService,
 * OR a fully deterministic piece of Laravel code that never involves
 * OpenAI at all (e.g. a future lightweight intent router that recognizes
 * "show me order ORD-000123" and calls the order-lookup tool directly, at
 * zero AI cost — see the AI Agent audit's cost-optimization requirement).
 *
 * Two entry points, matching AiTool vs AiMutatingTool:
 *  - call(): executes a tool's handle() immediately — the only path for a
 *    read-only AiTool, and (separately) the path a mutating tool's
 *    handle() is reached through ONLY after user confirmation (see
 *    Tenant\AiChatController::confirm()).
 *  - propose(): for an AiMutatingTool only — validates via preview() and
 *    returns a summary + resolved args to store as an AiPendingAction,
 *    performing no mutation. AiChatService's tool-calling loop uses
 *    isMutating() to decide which of the two to call for a given
 *    AI-requested tool call.
 *
 * Registered from config('ai.tools') — see App\Providers\AppServiceProvider
 * — the same config-driven, container-resolved shape already used for
 * AiProviderInterface/DomainDriver. Adding a new tool means adding its
 * class to that config array, never touching this class.
 */
class AiToolRegistry
{
    /** @var array<string, AiTool> */
    protected array $tools = [];

    /** @param  iterable<AiTool>  $tools */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return AiTool[] */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Executes one tool by name. $tenantId must come from the trusted
     * caller — see AiTool::handle()'s docblock; this method additionally
     * strips any tenant-shaped key out of $args defensively, so a
     * malformed or adversarial AI function-call argument set can never
     * even reach a tool implementation with one present, on top of every
     * individual tool never reading such a key in the first place.
     *
     * Never throws — a tool implementation raising an exception (a bug, a
     * genuinely unexpected DB state, anything) is caught here and turned
     * into AiToolResult::failure() plus a safe log line, exactly the same
     * "never crash the caller" posture AiProviderInterface implementations
     * already follow.
     */
    public function call(string $name, int $tenantId, array $args): AiToolResult
    {
        $tool = $this->tools[$name] ?? null;

        if (! $tool) {
            return AiToolResult::failure("Unknown tool: {$name}");
        }

        unset($args['tenant_id'], $args['tenant_slug'], $args['currentTenant']);

        try {
            return AiToolResult::success($tool->handle($tenantId, $args));
        } catch (\Throwable $e) {
            // Never log $e->getMessage() — same caution every other
            // integration in this codebase applies to exception text,
            // since it can echo query/credential details.
            Log::warning('AI tool execution failed.', [
                'tool' => $name,
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return AiToolResult::failure('Tool execution failed.');
        }
    }

    /**
     * The AI-mediated entry point for a MUTATING tool — validates via
     * preview() and returns a summary + resolved args, performing no
     * mutation. Refuses (failure()) for anything that isn't an
     * AiMutatingTool, so this can never accidentally become a second way
     * to invoke a read-only tool.
     */
    public function propose(string $name, int $tenantId, array $args): AiToolResult
    {
        $tool = $this->tools[$name] ?? null;

        if (! $tool) {
            return AiToolResult::failure("Unknown tool: {$name}");
        }

        if (! $tool instanceof AiMutatingTool) {
            return AiToolResult::failure("Tool {$name} is not mutating — call() it directly instead.");
        }

        unset($args['tenant_id'], $args['tenant_slug'], $args['currentTenant']);

        try {
            $preview = $tool->preview($tenantId, $args);
        } catch (\Throwable $e) {
            Log::warning('AI tool preview failed.', [
                'tool' => $name,
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return AiToolResult::failure('Tool preview failed.');
        }

        if (isset($preview['error'])) {
            return AiToolResult::failure((string) $preview['error']);
        }

        return AiToolResult::success($preview);
    }

    /**
     * OpenAI function-calling 'tools' array shape, ready to pass straight
     * into a chat completion request — see App\Services\AI\AiChatService,
     * the only caller that passes this into a live conversation.
     * App\Services\AI\AiAgentService (the public, unauthenticated
     * Messenger auto-reply flow) never receives a tools schema and so can
     * never reach any tool, mutating or not.
     *
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array}}>
     */
    public function toOpenAiSchema(): array
    {
        return array_map(fn (AiTool $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parametersSchema(),
            ],
        ], $this->all());
    }
}
