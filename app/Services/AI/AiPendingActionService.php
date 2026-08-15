<?php

namespace App\Services\AI;

use App\Models\AiPendingAction;
use App\Services\AI\Tools\AiToolRegistry;

/**
 * Single source of truth for the confirmation-system lifecycle — see
 * database/sql/chunk34.sql's docblock for the full design. Every method
 * here takes tenant_id/user_id explicitly from the trusted caller
 * (Tenant\AiChatController, always inside an authenticated request),
 * exactly like AiCreditService/AiToolRegistry — never inferred from
 * anything the request body itself supplies.
 */
class AiPendingActionService
{
    public function __construct(protected AiToolRegistry $tools) {}

    /**
     * Stores a mutating tool's preview() result as a new pending action —
     * called by AiChatService right after AiToolRegistry::propose()
     * succeeds. Never performs the mutation itself.
     */
    public function propose(int $tenantId, int $userId, ?int $conversationId, string $toolName, string $summary, array $resolvedArgs): AiPendingAction
    {
        return AiPendingAction::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'tool_name' => $toolName,
            'resolved_args' => $resolvedArgs,
            'summary' => $summary,
            'status' => 'pending',
            'expires_at' => now()->addMinutes((int) config('ai.pending_action_ttl_minutes', 15)),
        ]);
    }

    /**
     * Executes a pending action's mutation — the ONLY place any mutating
     * tool's handle() is ever reached from the AI-mediated surface, and
     * only with $action->resolved_args (the server-stored snapshot from
     * propose() above), never anything the confirm request itself
     * supplies. Caller (Tenant\AiChatController::confirm()) is
     * responsible for verifying $action belongs to the requesting
     * tenant/user before calling this.
     *
     * @return array{success: bool, message: string} The tool's own result
     *                                               (or a generic expiry/state message) — always has at least these two keys.
     */
    public function confirm(AiPendingAction $action): array
    {
        if ($action->status !== 'pending') {
            return ['success' => false, 'message' => 'এই অ্যাকশনটি ইতিমধ্যে প্রসেস করা হয়ে গেছে।'];
        }

        if ($action->isExpired()) {
            $action->update(['status' => 'expired']);

            return ['success' => false, 'message' => 'কনফার্মেশনের মেয়াদ শেষ হয়ে গেছে — আবার অনুরোধ করুন।'];
        }

        $result = $this->tools->call($action->tool_name, $action->tenant_id, $action->resolved_args);

        if (! $result->successful) {
            $action->update(['status' => 'failed', 'error' => $result->error]);

            return ['success' => false, 'message' => $result->error ?? 'অ্যাকশনটি সম্পন্ন করা যায়নি।'];
        }

        $data = $result->data;
        $succeeded = $data['success'] ?? true;

        $action->update([
            'status' => $succeeded ? 'confirmed' : 'failed',
            'result' => $data,
            'error' => $succeeded ? null : ($data['message'] ?? null),
            'confirmed_at' => now(),
        ]);

        return ['success' => (bool) $succeeded, 'message' => $data['message'] ?? ($succeeded ? 'সম্পন্ন হয়েছে।' : 'সম্পন্ন করা যায়নি।')];
    }

    public function reject(AiPendingAction $action): void
    {
        if ($action->status === 'pending') {
            $action->update(['status' => 'rejected']);
        }
    }
}
