<?php

namespace App\Services\AI;

use App\Services\AI\Providers\AiProviderInterface;
use App\Services\AI\Tools\AiMutatingTool;
use App\Services\AI\Tools\AiToolRegistry;

/**
 * The tenant-authenticated panel chat's orchestrator (Phase 4) — the
 * counterpart to AiAgentService, but WITH tool calling. Deliberately a
 * separate class rather than adding tools to AiAgentService: that class's
 * whole contract (see its docblock) is "no database access and no
 * tools", which is a real security boundary for the public,
 * unauthenticated Messenger flow, not an arbitrary split. Both classes
 * share the same AiProviderInterface underneath.
 *
 * Runs an agentic loop: ask the model for a reply with the tool schema
 * attached. For each tool call the model makes:
 *  - a read-only AiTool is executed immediately via AiToolRegistry::call()
 *    and its result fed back, same as before Phase 5.
 *  - an AiMutatingTool (Phase 5) is NEVER executed here — only proposed
 *    via AiToolRegistry::propose(), and the loop stops immediately,
 *    returning the proposal's summary as the reply plus enough
 *    information for Tenant\AiChatController to store it as an
 *    AiPendingAction. Actual execution only ever happens later, from a
 *    real user confirming — see AiPendingActionService::confirm().
 * Otherwise repeats until the model returns a final text reply or
 * config('ai.chat_max_tool_iterations') is hit.
 *
 * Has NO knowledge of ai_agent_enabled or any other tenant toggle — same
 * separation of concerns Phase 1 already established (ProcessAiAgentMessage
 * checks the toggle, AiAgentService itself never does). Tenant\AiChatController
 * checks the toggle before ever calling this.
 */
class AiChatService
{
    public function __construct(
        protected AiProviderInterface $provider,
        protected AiToolRegistry $tools,
        protected AiCreditService $credit,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $conversationHistory  Prior
     *                                                                                 USER-VISIBLE turns only (never raw tool-call/tool-result
     *                                                                                 messages — see database/sql/chunk33.sql's docblock for why),
     *                                                                                 oldest first.
     * @return array{reply: string, requires_confirmation?: bool, tool_name?: string, resolved_args?: array}|null
     *                                                                                                            Null when credit is exhausted, the provider failed, or the
     *                                                                                                            tool-calling loop exceeded its iteration cap without ever
     *                                                                                                            producing a final reply or proposal. requires_confirmation=true
     *                                                                                                            means 'reply' is a mutation's summary text, not a normal AI
     *                                                                                                            reply — the caller must create an AiPendingAction from
     *                                                                                                            tool_name/resolved_args rather than just showing 'reply' as-is.
     */
    public function reply(int $tenantId, string $businessName, array $conversationHistory, string $userMessage): ?array
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($businessName)]],
            $conversationHistory,
            [['role' => 'user', 'content' => $userMessage]]
        );

        $schema = $this->tools->toOpenAiSchema();
        $maxIterations = (int) config('ai.chat_max_tool_iterations', 5);

        for ($i = 0; $i < $maxIterations; $i++) {
            // Re-checked on every round trip, not just once up front — a
            // single user message can trigger several OpenAI calls (one
            // per tool-calling round), and each one costs real credit;
            // this is the same "re-check before every OpenAI call" rule
            // ProcessAiAgentMessage already established, just applied
            // per-iteration here instead of per-message.
            if (! $this->credit->hasCredit($tenantId)) {
                return null;
            }

            $response = $this->provider->chat($messages, $schema);

            if (! $response->successful) {
                // The provider already logged why.
                return null;
            }

            $this->credit->recordUsage(
                $tenantId,
                $response->inputTokens,
                $response->outputTokens,
                (string) $response->model,
                contextType: 'panel_chat',
            );

            if (! $response->toolCalls) {
                return ['reply' => $response->reply ?? ''];
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->reply,
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => json_encode($call['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ], $response->toolCalls),
            ];

            foreach ($response->toolCalls as $call) {
                $toolName = (string) $call['name'];
                $tool = $this->tools->get($toolName);

                if ($tool instanceof AiMutatingTool) {
                    $proposal = $this->tools->propose($toolName, $tenantId, $call['arguments']);

                    if ($proposal->successful) {
                        // Stop the whole turn here — the pending action's
                        // summary IS the reply shown to the user; nothing
                        // in this batch or the conversation continues
                        // until they confirm or reject it.
                        return [
                            'reply' => $proposal->data['summary'],
                            'requires_confirmation' => true,
                            'tool_name' => $toolName,
                            'resolved_args' => $proposal->data['resolved_args'],
                        ];
                    }

                    // A validation failure (bad args, product not found,
                    // insufficient stock) — let the model see it and
                    // respond (e.g. ask the user to clarify) rather than
                    // silently terminating the turn.
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'],
                        'content' => json_encode(['error' => $proposal->error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];

                    continue;
                }

                $result = $this->tools->call($toolName, $tenantId, $call['arguments']);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($result->successful ? $result->data : ['error' => $result->error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        // Exceeded the iteration cap without a final reply or proposal —
        // treat as a failure rather than showing the user a half-finished
        // answer. Every round trip up to this point was still legitimately
        // charged above; this only affects what's returned to the caller.
        return null;
    }

    protected function systemPrompt(string $businessName): string
    {
        $base = (string) config('ai.chat_system_prompt');

        return $base."\n\nYou are currently assisting the business named \"{$businessName}\".";
    }
}
