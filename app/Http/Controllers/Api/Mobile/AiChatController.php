<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\AiPendingAction;
use App\Models\StoreSetting;
use App\Services\AI\AiChatService;
use App\Services\AI\AiCreditService;
use App\Services\AI\AiPendingActionService;
use Illuminate\Http\Request;

/**
 * Mirrors Tenant\AiChatController's real capability exactly — the
 * staff-only tool-calling panel chat (Phase 4/5), reusing
 * AiChatService::reply()/AiPendingActionService::propose()/confirm()/
 * reject() unmodified. This is a genuinely separate system from the
 * customer-facing Messenger/WhatsApp auto-reply agent
 * (ProcessAiAgentMessage/AiProductKnowledgeService, currently unrelated
 * WIP elsewhere in this repo) — same distinction
 * Tenant\AiChatController's own docblock draws, confirmed by neither this
 * controller nor any class it calls appearing in that WIP's changed-files
 * list. Nothing in that WIP was read or modified to build this.
 *
 * reject() is a real addition beyond docs/api-contract.md's "the one
 * confirmed ai-chat path" note (which only knew about confirm()) —
 * AiPendingActionService::reject() already exists and is real, so this
 * exposes it rather than leaving the mobile app's reject action as a
 * client-side-only fake dismissal.
 */
class AiChatController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('currentTenant');
        $user = $request->user();

        if (! AiConversation::tablesReady()) {
            return response()->json(['data' => [], 'ai_agent_enabled' => false, 'credit_balance' => 0]);
        }

        $conversation = AiConversation::where('user_id', $user->id)->first();
        $messages = $conversation
            ? $conversation->messages()->with('pendingAction')->orderBy('id')->get()
            : collect();

        return response()->json([
            'data' => $messages->map(fn (AiConversationMessage $m) => $this->presentMessage($m))->values(),
            'ai_agent_enabled' => $this->isAiAgentEnabled(),
            'credit_balance' => app(AiCreditService::class)->balance($tenant->id),
        ]);
    }

    public function send(Request $request, AiChatService $chat, AiPendingActionService $pendingActions)
    {
        $data = $request->validate(['message' => 'required|string|max:2000']);

        $tenant = app('currentTenant');
        $user = $request->user();

        if (! AiConversation::tablesReady()) {
            return response()->json(['message' => 'AI চ্যাট এখনো সেটআপ হয়নি।'], 422);
        }

        if (! $this->isAiAgentEnabled()) {
            return response()->json(['message' => 'AI এজেন্ট বন্ধ আছে। Settings থেকে চালু করুন।'], 422);
        }

        if (! app(AiCreditService::class)->hasCredit($tenant->id)) {
            return response()->json(['message' => 'AI ক্রেডিট শেষ হয়ে গেছে। নতুন ক্রেডিটের জন্য সুপার অ্যাডমিনের সাথে যোগাযোগ করুন।'], 422);
        }

        $conversation = AiConversation::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $context = $conversation->messages()
            ->orderByDesc('id')
            ->limit((int) config('ai.context_messages', 10))
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $conversation->messages()->create(['tenant_id' => $tenant->id, 'role' => 'user', 'content' => $data['message']]);

        $result = $chat->reply($tenant->id, $tenant->store_name, $context, $data['message']);

        if (! $result) {
            return response()->json(['message' => 'AI থেকে উত্তর পাওয়া যায়নি। একটু পর আবার চেষ্টা করুন।'], 422);
        }

        $pendingActionId = null;

        if ($result['requires_confirmation'] ?? false) {
            $pendingActionId = $pendingActions->propose(
                $tenant->id,
                $user->id,
                $conversation->id,
                $result['tool_name'],
                $result['reply'],
                $result['resolved_args'],
            )->id;
        }

        $assistantMessage = $conversation->messages()->create([
            'tenant_id' => $tenant->id,
            'role' => 'assistant',
            'content' => $result['reply'],
            'pending_action_id' => $pendingActionId,
        ]);

        return response()->json($this->presentMessage($assistantMessage->load('pendingAction')), 201);
    }

    public function confirm(Request $request, int $pendingAction, AiPendingActionService $pendingActions)
    {
        $tenant = app('currentTenant');
        $action = AiPendingAction::where('tenant_id', $tenant->id)->findOrFail($pendingAction);
        abort_if($action->user_id !== $request->user()->id, 404);

        $result = $pendingActions->confirm($action);
        $this->recordActionOutcome($action, $result['message']);

        return response()->json($this->presentPendingAction($action->fresh()));
    }

    public function reject(Request $request, int $pendingAction, AiPendingActionService $pendingActions)
    {
        $tenant = app('currentTenant');
        $action = AiPendingAction::where('tenant_id', $tenant->id)->findOrFail($pendingAction);
        abort_if($action->user_id !== $request->user()->id, 404);

        $pendingActions->reject($action);
        $this->recordActionOutcome($action, 'বাতিল করা হয়েছে।');

        return response()->json($this->presentPendingAction($action->fresh()));
    }

    protected function recordActionOutcome(AiPendingAction $pendingAction, string $message): void
    {
        if (! $pendingAction->conversation_id) {
            return;
        }

        AiConversation::find($pendingAction->conversation_id)?->messages()->create([
            'tenant_id' => $pendingAction->tenant_id,
            'role' => 'assistant',
            'content' => $message,
        ]);
    }

    protected function isAiAgentEnabled(): bool
    {
        return StoreSetting::where('key', 'ai_agent_enabled')->value('value') === '1';
    }

    protected function presentMessage(AiConversationMessage $m): array
    {
        return [
            'id' => $m->id,
            'sender' => $m->role === 'user' ? 'staff' : 'assistant',
            'text' => $m->content,
            'sent_at' => $m->created_at?->toIso8601String(),
            'pending_action' => $m->pendingAction ? $this->presentPendingAction($m->pendingAction) : null,
        ];
    }

    protected function presentPendingAction(AiPendingAction $action): array
    {
        return [
            'id' => $action->id,
            'summary' => $action->summary,
            'status' => $action->status,
            'result_message' => $action->result['message'] ?? null,
        ];
    }
}
