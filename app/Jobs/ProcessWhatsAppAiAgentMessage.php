<?php

namespace App\Jobs;

use App\Models\AiWhatsAppMessageJob;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\WhatsAppMessage;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiCreditService;
use App\Services\WhatsApp\WhatsAppSendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the AI Customer Support Agent for one inbound WhatsApp text
 * message, entirely outside the Meta webhook's request/response cycle —
 * the WhatsApp counterpart of App\Jobs\ProcessAiAgentMessage (Messenger),
 * structurally mirrored one-for-one rather than sharing code with it. See
 * that class's docblock for the full "why a separate job, not a
 * generalized one" reasoning: the two channels differ in message model,
 * toggle key, and — most importantly — outbound send shape (Messenger
 * needs a manually-resolved Page token; WhatsAppSendService resolves
 * everything itself from a Tenant model), enough real divergence that a
 * shared implementation would need its own abstraction layer touching
 * the already-proven Messenger job for no safety benefit.
 *
 * Reuses App\Services\AI\AiAgentService, AiCreditService, and
 * WhatsAppSendService exactly as they already exist — no new OpenAI
 * client, no new WhatsApp Graph API sender.
 *
 * Every step re-verifies its own precondition, same posture as
 * ProcessAiAgentMessage: the tenant could be gone, AI Agent could have
 * been switched back off, or (via AiWhatsAppMessageJob::claim()) this
 * exact message could already be mid-flight from an earlier attempt.
 */
class ProcessWhatsAppAiAgentMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    // Same reasoning as ProcessAiAgentMessage::$timeout — generous
    // headroom over AiAgentService's own OpenAI call plus the WhatsApp
    // send, well inside the --max-time=50 cron-worker budget in
    // routes/console.php (shared with the Messenger job — no second cron
    // entry needed).
    public int $timeout = 30;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $whatsAppMessageId,
    ) {}

    public function handle(AiAgentService $ai, AiCreditService $credit, WhatsAppSendService $whatsapp): void
    {
        if (! AiWhatsAppMessageJob::tablesReady()) {
            return;
        }

        if (! AiWhatsAppMessageJob::claim($this->tenantId, $this->whatsAppMessageId)) {
            // Already claimed by a prior attempt (retry), already
            // completed/failed, or somehow never recorded — in every
            // case, generating or sending anything here would risk a
            // duplicate reply.
            return;
        }

        try {
            $sent = $this->process($ai, $credit, $whatsapp);

            if ($sent) {
                AiWhatsAppMessageJob::markCompleted($this->tenantId, $this->whatsAppMessageId);
            } else {
                // process() returned early without throwing — tenant/
                // message no longer eligible, AI got turned back off,
                // credit exhausted, OpenAI failed, or the WhatsApp send
                // failed. None of these are exceptions (each is an
                // expected, already-logged-if-relevant outcome), but none
                // of them sent a reply either, so this is not 'completed'.
                AiWhatsAppMessageJob::markFailed($this->tenantId, $this->whatsAppMessageId);
            }
        } catch (\Throwable $e) {
            AiWhatsAppMessageJob::markFailed($this->tenantId, $this->whatsAppMessageId);

            Log::warning('WhatsApp AI agent job: processing failed.', [
                'tenant_id' => $this->tenantId,
                'whatsapp_message_id' => $this->whatsAppMessageId,
                'exception' => get_class($e),
            ]);

            // Deliberately not rethrown — same reasoning as
            // ProcessAiAgentMessage: markFailed() above already makes any
            // further Laravel-level retry a safe no-op via the claim()
            // guard.
        }
    }

    /**
     * @return bool true only when a reply was actually generated and
     *              successfully sent — every other outcome (tenant/message gone, AI
     *              or WhatsApp-auto-reply toggled back off, credit exhausted,
     *              OpenAI failure, WhatsApp send failure) returns false without
     *              throwing, and the caller marks the job 'failed' for any of them.
     */
    protected function process(AiAgentService $ai, AiCreditService $credit, WhatsAppSendService $whatsapp): bool
    {
        $tenant = Tenant::withoutGlobalScopes()->find($this->tenantId);

        if (! $tenant) {
            return false;
        }

        // Re-check both toggles — the tenant may have switched either
        // back off after the webhook dispatched this job but before a
        // worker picked it up. ai_agent_enabled is the master AI switch
        // (shared with Messenger/panel chat); whatsapp_ai_auto_reply_enabled
        // is WhatsApp-channel-specific — both must be on for this
        // WhatsApp-triggered call to proceed.
        if (! $this->isAiAgentEnabled($this->tenantId) || ! $this->isWhatsAppAutoReplyEnabled($this->tenantId)) {
            return false;
        }

        // Re-check credit — another concurrent message for this same
        // tenant could have exhausted the balance between dispatch and
        // this worker picking the job up. Must NEVER touch
        // ai_agent_enabled/whatsapp_ai_auto_reply_enabled or any other
        // configuration — see AiCreditAccount's docblock.
        if (! $credit->hasCredit($this->tenantId)) {
            return false;
        }

        $message = WhatsAppMessage::withoutGlobalScopes()
            ->where('id', $this->whatsAppMessageId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        // direction !== 'in' can only happen if this job were ever
        // (mis)dispatched for an outbound row — WhatsAppWebhookController::
        // handleIncomingMessage() only ever creates 'in' rows and is the
        // only dispatcher, so this is belt-and-suspenders, not a real
        // path — but re-checked anyway per this job's "never trust an
        // earlier check is still true" posture.
        if (! $message || $message->direction !== 'in' || ! $message->message_text) {
            return false;
        }

        $waId = $message->wa_id;

        $history = $this->recentHistory($this->tenantId, $waId, $message->id);

        $result = $ai->generateReply($tenant->store_name, $history, $message->message_text);

        if (! $result) {
            // AiAgentService already logged why.
            return false;
        }

        // Deduct credit / record token usage the moment the OpenAI call
        // itself succeeded — the cost was incurred here regardless of
        // whether the WhatsApp send below succeeds, so this must not wait
        // on that later step (see AiCreditService::recordUsage()'s
        // docblock, and ProcessAiAgentMessage's identical reasoning).
        $credit->recordUsage(
            $this->tenantId,
            $result['input_tokens'],
            $result['output_tokens'],
            $result['model'],
            contextType: 'whatsapp_reply',
            contextId: $message->id,
        );

        // WhatsAppSendService::sendText() takes the Tenant model directly
        // and resolves the connected phone number/access token itself —
        // unlike Messenger's MessengerApi::sendMessage(), no manual token
        // resolution step is needed here at all. It also persists the
        // resulting whatsapp_messages row itself (success or failure) —
        // see its own docblock — so this job must NOT create one too.
        $sendResult = $whatsapp->sendText($tenant, $waId, $result['reply']);

        if (! $sendResult->successful) {
            // Never log $sendResult->errorMessage — same "may echo
            // request/credential details" caution WhatsAppSendService
            // itself already applies to this exact value.
            Log::warning('WhatsApp AI agent job: WhatsApp send failed.', [
                'tenant_id' => $this->tenantId,
                'error_code' => $sendResult->errorCode,
            ]);

            return false;
        }

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
     * switch above — same "no row = disabled" shape as
     * isMessengerAutoReplyEnabled() in ProcessAiAgentMessage.
     */
    protected function isWhatsAppAutoReplyEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'whatsapp_ai_auto_reply_enabled')
            ->value('value') === '1';
    }

    /**
     * Minimal, safe context: only this conversation's own recent
     * messages, nothing from any other table — same shape as
     * ProcessAiAgentMessage::recentHistory(), reading whatsapp_messages/
     * wa_id instead of messenger_messages/sender_psid. No tools, no
     * arbitrary database access — see AiAgentService's docblock.
     *
     * @return array<int, array{role: string, content: string}>
     */
    protected function recentHistory(int $tenantId, string $waId, int $beforeMessageId): array
    {
        $limit = (int) config('ai.context_messages', 10);

        if ($limit <= 0) {
            return [];
        }

        return WhatsAppMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('wa_id', $waId)
            ->where('id', '<', $beforeMessageId)
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (WhatsAppMessage $m) => [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => (string) $m->message_text,
            ])
            ->all();
    }
}
