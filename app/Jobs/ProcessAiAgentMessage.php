<?php

namespace App\Jobs;

use App\Models\AiAgentMessageJob;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiCreditService;
use App\Services\Messenger\MessengerApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the AI Customer Support Agent for one inbound Messenger text
 * message, entirely outside the Meta webhook's request/response cycle —
 * see MessengerWebhookController::maybeDispatchAiAgent() for the
 * dispatch side and its docblock for why this must never run
 * synchronously in the webhook.
 *
 * Receives only plain identifiers (tenant/message IDs), not Eloquent
 * models — the database driver queue serializes the whole job payload as
 * JSON, and there is no reason to pull a full model graph through that
 * just to re-load it fresh here anyway (a stale serialized model would
 * defeat the "re-check everything" requirement below).
 *
 * Every step re-verifies its own precondition rather than trusting the
 * webhook's checks are still true by the time a worker picks this up:
 * the tenant could be gone, AI Agent could have been switched back off,
 * or (via App\Models\AiAgentMessageJob::claim()) this exact message could
 * already be mid-flight from an earlier attempt. See that model's
 * docblock for the full duplicate-send guard design.
 */
class ProcessAiAgentMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    // Generous headroom over AiAgentService's own 20s OpenAI call
    // (config('ai.timeout_seconds')) plus the Messenger send — well
    // inside the --max-time=50 cron-worker budget documented in
    // routes/console.php, so a hung attempt can never survive into the
    // next minute's scheduled run. Enforcement requires the pcntl
    // extension (Laravel uses pcntl_alarm() for this); where it isn't
    // available, config('queue.connections.database.retry_after') = 90s
    // is the real backstop — same passive "reservation expires, another
    // worker may retry" mechanism this queue connection already relies
    // on regardless of this property.
    public int $timeout = 30;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $messengerMessageId,
    ) {}

    public function handle(AiAgentService $ai, AiCreditService $credit, MessengerApi $api): void
    {
        if (! AiAgentMessageJob::tablesReady()) {
            return;
        }

        if (! AiAgentMessageJob::claim($this->tenantId, $this->messengerMessageId)) {
            // Already claimed by a prior attempt (retry), already
            // completed/failed, or somehow never recorded — in every
            // case, generating or sending anything here would risk a
            // duplicate reply.
            return;
        }

        try {
            $sent = $this->process($ai, $credit, $api);

            if ($sent) {
                AiAgentMessageJob::markCompleted($this->tenantId, $this->messengerMessageId);
            } else {
                // process() returned early without throwing — tenant/
                // message no longer eligible, AI got turned back off,
                // OpenAI failed, or the Messenger send failed. None of
                // these are exceptions (each is an expected, already-
                // logged-if-relevant outcome), but none of them sent a
                // reply either, so this is not 'completed'.
                AiAgentMessageJob::markFailed($this->tenantId, $this->messengerMessageId);
            }
        } catch (\Throwable $e) {
            AiAgentMessageJob::markFailed($this->tenantId, $this->messengerMessageId);

            Log::warning('AI agent job: processing failed.', [
                'tenant_id' => $this->tenantId,
                'messenger_message_id' => $this->messengerMessageId,
                'exception' => get_class($e),
            ]);

            // Deliberately not rethrown: markFailed() above already makes
            // any further Laravel-level retry of this job a safe no-op
            // via the claim() guard (status is no longer 'pending'), so
            // there is nothing left for the framework's own retry/
            // failed_jobs machinery to protect against here — only noise
            // to avoid.
        }
    }

    /**
     * @return bool true only when a reply was actually generated and
     *              successfully sent — every other outcome (tenant/message gone, AI
     *              or Messenger-auto-reply toggled back off, credit exhausted,
     *              OpenAI failure, Messenger send failure) returns false without
     *              throwing, and the caller marks the job 'failed' for any of
     *              them. None of these send a fallback/error message to the
     *              customer, per this phase's spec.
     */
    protected function process(AiAgentService $ai, AiCreditService $credit, MessengerApi $api): bool
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return false;
        }

        // Re-check both toggles — the tenant may have switched either
        // back off after the webhook dispatched this job but before a
        // worker picked it up. This is the "STOP without replying"
        // requirement. Two independent toggles (see
        // MessengerWebhookController::maybeDispatchAiAgent()'s docblock):
        // ai_agent_enabled is the master AI switch, messenger_ai_auto_reply_enabled
        // is Messenger-channel-specific — both must be on for this
        // Messenger-triggered call to proceed.
        if (! $this->isAiAgentEnabled($this->tenantId) || ! $this->isMessengerAutoReplyEnabled($this->tenantId)) {
            return false;
        }

        // Re-check credit — another concurrent message for this same
        // tenant could have exhausted the balance between dispatch and
        // this worker picking the job up. Exhausted credit must stop AI
        // processing exactly like the toggle being off does, and must
        // NEVER touch ai_agent_enabled/messenger_ai_auto_reply_enabled or
        // any other configuration — see AiCreditAccount's docblock.
        if (! $credit->hasCredit($this->tenantId)) {
            return false;
        }

        $message = MessengerMessage::withoutGlobalScopes()
            ->where('id', $this->messengerMessageId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (! $message || $message->direction !== 'in' || ! $message->message_text) {
            return false;
        }

        $psid = $message->sender_psid;

        $history = $this->recentHistory($this->tenantId, $psid, $message->id);

        $result = $ai->generateReply($tenant->store_name, $history, $message->message_text);

        if (! $result) {
            // AiAgentService already logged why.
            return false;
        }

        // Deduct credit / record token usage the moment the OpenAI call
        // itself succeeded — the cost was incurred here regardless of
        // whether the Messenger send below succeeds, so this must not
        // wait on that later step (see AiCreditService::recordUsage()'s
        // docblock).
        $credit->recordUsage(
            $this->tenantId,
            $result['input_tokens'],
            $result['output_tokens'],
            $result['model'],
            contextType: 'messenger_reply',
            contextId: $message->id,
        );

        $reply = $result['reply'];

        $token = $this->resolveOutboundToken($this->tenantId, $psid);

        if (! $token) {
            return false;
        }

        $sendResult = $api->sendMessage($psid, $reply, $token);

        if (isset($sendResult['error'])) {
            // Never log $sendResult['error']['message'] — same "may echo
            // request details" caution already applied throughout this
            // codebase's other Graph API error handling.
            Log::warning('AI agent job: Messenger send failed.', [
                'tenant_id' => $this->tenantId,
                'error_type' => $sendResult['error']['type'] ?? null,
            ]);

            return false;
        }

        // Record the AI's reply the same way a human panel reply already
        // is (MessengerInboxController::reply()) — so it appears in the
        // tenant's Messenger inbox, and so the mid-based dedup in
        // MessengerWebhookController::handleEvent() already finds it
        // recorded when the matching echo event for this send arrives.
        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'sender_psid' => $psid,
            'mid' => $sendResult['message_id'] ?? null,
            'message_text' => $reply,
            'direction' => 'out',
            'status' => 'contacted',
        ]);

        return true;
    }

    protected function isAiAgentEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'ai_agent_enabled')
            ->value('value') === '1';
    }

    /**
     * Channel-specific toggle, independent of the master ai_agent_enabled
     * switch above — see MessengerWebhookController::maybeDispatchAiAgent()'s
     * docblock for the full reasoning. No row = disabled, same "no row =
     * off" default every other tenant toggle in this codebase uses.
     */
    protected function isMessengerAutoReplyEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'messenger_ai_auto_reply_enabled')
            ->value('value') === '1';
    }

    /**
     * Minimal, safe context: only this conversation's own recent
     * messages, nothing from any other table. No tools, no arbitrary
     * database access — see AiAgentService's docblock.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function recentHistory(int $tenantId, string $psid, int $beforeMessageId): array
    {
        $limit = (int) config('ai.context_messages', 10);

        if ($limit <= 0) {
            return [];
        }

        return MessengerMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sender_psid', $psid)
            ->where('id', '<', $beforeMessageId)
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (MessengerMessage $m) => [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => (string) $m->message_text,
            ])
            ->all();
    }

    /**
     * Duplicate of MessengerInboxController::resolveReplyToken()'s logic,
     * not a shared extraction — that method is protected/controller-bound
     * and assumes an authenticated tenant panel request (app('currentTenant')
     * bound), neither of which is true inside a queued job with no HTTP
     * request at all. Mirrors its "never substitute a different Page's
     * token for this conversation" behavior: a conversation tied to a
     * specific, now-disconnected Page gets no reply at all rather than
     * one sent from the wrong Page.
     */
    protected function resolveOutboundToken(int $tenantId, string $psid): ?string
    {
        $facebookPageId = FacebookPage::tablesReady()
            ? MessengerMessage::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sender_psid', $psid)
                ->whereNotNull('facebook_page_id')
                ->orderByDesc('id')
                ->value('facebook_page_id')
            : null;

        if ($facebookPageId) {
            return FacebookPage::withoutGlobalScopes()
                ->where('id', $facebookPageId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->where('status', 'active')
                ->value('page_access_token');
        }

        return MessengerSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->value('page_access_token');
    }
}
