<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\StoreSetting;
use App\Services\AI\AiChatService;
use App\Services\AI\AiCreditService;
use App\Services\AI\AiPendingActionService;
use Illuminate\Http\Request;

/**
 * The tenant-authenticated panel chat surface (Phase 4) — the first live
 * consumer of App\Services\AI\Tools\AiToolRegistry. Reachable only
 * through the standard auth:tenant + check.subscription panel routes
 * (see routes/web.php), unlike the public Messenger auto-reply flow,
 * which is exactly why tool-calling was withheld from that flow in
 * Phase 3: several tools here return business-sensitive data (customer
 * records, revenue) that must never be reachable by an anonymous
 * Messenger visitor.
 *
 * Gated by the SAME master ai_agent_enabled toggle Messenger uses (see
 * Tenant\SettingController::aiAgent()) — deliberately NOT gated by
 * messenger_ai_auto_reply_enabled, which is Messenger-channel-specific
 * only. Also gated by AiCreditService::hasCredit(), same as Messenger.
 *
 * Phase 5 (confirm()/reject()): the ONLY place a mutating tool's
 * handle() is ever reached — see AiPendingActionService::confirm(). Both
 * actions verify the pending action belongs to THIS tenant (route-model
 * binding, BelongsToTenant-scoped) AND this exact user (checked
 * explicitly below, since BelongsToTenant only enforces the tenant
 * boundary) — one staff member must never be able to confirm/reject
 * another's proposed action.
 */
class AiChatController extends Controller
{
    public function index()
    {
        $tenant = app('currentTenant');
        $user = auth('tenant')->user();

        $conversation = AiConversation::tablesReady()
            ? AiConversation::where('user_id', $user->id)->first()
            : null;

        return view('tenant.ai-chat', [
            'messages' => $conversation ? $conversation->messages()->with('pendingAction')->orderBy('id')->get() : collect(),
            'aiAgentEnabled' => $this->isAiAgentEnabled(),
            'aiCreditBalance' => app(AiCreditService::class)->balance($tenant->id),
        ]);
    }

    public function send(Request $request, AiChatService $chat, AiPendingActionService $pendingActions)
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $tenant = app('currentTenant');
        $user = auth('tenant')->user();

        if (! AiConversation::tablesReady()) {
            return back()->with('error', 'AI চ্যাট এখনো সেটআপ হয়নি।');
        }

        if (! $this->isAiAgentEnabled()) {
            return back()->with('error', 'AI এজেন্ট বন্ধ আছে। Settings থেকে চালু করুন।');
        }

        if (! app(AiCreditService::class)->hasCredit($tenant->id)) {
            return back()->with('error', 'AI ক্রেডিট শেষ হয়ে গেছে। নতুন ক্রেডিটের জন্য সুপার অ্যাডমিনের সাথে যোগাযোগ করুন।');
        }

        $conversation = AiConversation::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        // Context is the last N stored turns BEFORE this new message —
        // same config('ai.context_messages') key ProcessAiAgentMessage
        // uses for Messenger, applied here to ai_conversation_messages
        // instead.
        $context = $conversation->messages()
            ->orderByDesc('id')
            ->limit((int) config('ai.context_messages', 10))
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $conversation->messages()->create([
            'tenant_id' => $tenant->id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $result = $chat->reply($tenant->id, $tenant->store_name, $context, $data['message']);

        if (! $result) {
            // The user's own message above is already saved regardless —
            // only the AI's reply failed to generate, same "the
            // conversation record is never lost" posture the Messenger
            // flow's message-storage-before-AI-processing already has.
            return back()->with('error', 'AI থেকে উত্তর পাওয়া যায়নি। একটু পর আবার চেষ্টা করুন।');
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

        $conversation->messages()->create([
            'tenant_id' => $tenant->id,
            'role' => 'assistant',
            'content' => $result['reply'],
            'pending_action_id' => $pendingActionId,
        ]);

        return back();
    }

    public function confirm(AiPendingAction $pendingAction, AiPendingActionService $pendingActions)
    {
        abort_if($pendingAction->user_id !== auth('tenant')->id(), 404);

        $result = $pendingActions->confirm($pendingAction);

        $this->recordActionOutcome($pendingAction, $result['message']);

        return back();
    }

    public function reject(AiPendingAction $pendingAction, AiPendingActionService $pendingActions)
    {
        abort_if($pendingAction->user_id !== auth('tenant')->id(), 404);

        $pendingActions->reject($pendingAction);

        $this->recordActionOutcome($pendingAction, 'বাতিল করা হয়েছে।');

        return back();
    }

    /** Appends the confirm/reject outcome as a new assistant message, so the conversation reads naturally. */
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

    /**
     * Runs inside an authenticated tenant request, where app('currentTenant')
     * is already bound — so, unlike ProcessAiAgentMessage/MessengerWebhookController
     * (which run outside any request and must scope explicitly), the
     * ambient BelongsToTenant scope is trustworthy here, same as
     * Tenant\SettingController::aiAgent() itself relies on.
     */
    protected function isAiAgentEnabled(): bool
    {
        return StoreSetting::where('key', 'ai_agent_enabled')->value('value') === '1';
    }
}
