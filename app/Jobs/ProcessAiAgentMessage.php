<?php

namespace App\Jobs;

use App\Models\AiAgentMessageJob;
use App\Models\AiTenantMemory;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\TenantProductImage;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiAudioTranscriptionService;
use App\Services\AI\AiConversationStyleService;
use App\Services\AI\AiCreditService;
use App\Services\AI\AiCustomerEmotionService;
use App\Services\AI\AiCustomerMemoryService;
use App\Services\AI\AiHandoffService;
use App\Services\AI\AiPostPurchaseContextService;
use App\Services\AI\AiProductImageMemoryService;
use App\Services\AI\AiProductKnowledgeService;
use App\Services\AI\AiTenantKnowledgeService;
use App\Services\AI\AiTenantMemoryService;
use App\Services\Messenger\MessengerApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
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

    /**
     * A handful of short, natural, non-gendered confirmations sent
     * alongside a "পণ্যের ছবি" product image — rotated deterministically
     * (never random, so this stays testable) rather than a single fixed
     * phrase repeated on every send. Deliberately never calls OpenAI to
     * word this — see AiProductImageMemoryService's docblock and
     * AiImageRequestResolution::sendAndStop()'s docblock for why a
     * confidently-resolved image never needs an AI call at all.
     */
    protected const PRODUCT_IMAGE_CAPTIONS = [
        'এই যে ছবিটা 😊',
        'অবশ্যই, ছবি পাঠালাম 😊',
        'নিন, ছবিটা দেখে নিন 😊',
    ];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $messengerMessageId,
    ) {}

    public function handle(AiAgentService $ai, AiCreditService $credit, MessengerApi $api, AiConversationStyleService $style, AiTenantKnowledgeService $knowledge, AiProductKnowledgeService $products, AiCustomerMemoryService $memory, AiCustomerEmotionService $emotion, AiAudioTranscriptionService $transcription, AiHandoffService $handoff, AiTenantMemoryService $memories, AiProductImageMemoryService $productImages, AiPostPurchaseContextService $postPurchase): void
    {
        if (! AiAgentMessageJob::tablesReady()) {
            return;
        }

        // Part 12/13 — message coalescing. Only possible once
        // database/sql/chunk51.sql's conversation_key column exists; on
        // an older schema $batchIds stays [$this->messengerMessageId]
        // and everything below behaves exactly as before coalescing
        // existed.
        $batchIds = [$this->messengerMessageId];

        if (AiAgentMessageJob::conversationKeyColumnReady()) {
            $conversationKey = AiAgentMessageJob::conversationKeyFor($this->tenantId, $this->messengerMessageId);

            if ($conversationKey) {
                if (AiAgentMessageJob::hasNewerPending($this->tenantId, $conversationKey, $this->messengerMessageId)) {
                    // A message that arrived after this one is still
                    // pending for the same conversation — its own
                    // (later-firing) delayed job will pick up this
                    // message as part of one coalesced turn. Returning
                    // here without claiming or marking anything leaves
                    // this row untouched ('pending') so that later job's
                    // batch query still finds it — see
                    // AiAgentMessageJob::coalescedBatchIds().
                    return;
                }

                $batchIds = AiAgentMessageJob::coalescedBatchIds(
                    $this->tenantId,
                    $conversationKey,
                    $this->messengerMessageId,
                    (int) config('ai.message_coalesce_max_batch', 8)
                );

                if (! in_array($this->messengerMessageId, $batchIds, true)) {
                    // Defensive — should not happen (this row was just
                    // confirmed 'pending'/claimable moments ago), but
                    // never silently drop the very message this job was
                    // dispatched for.
                    $batchIds[] = $this->messengerMessageId;
                    sort($batchIds);
                }
            }
        }

        if (! AiAgentMessageJob::claimBatch($this->tenantId, $batchIds)) {
            // Already claimed by a prior attempt (retry), already
            // completed/failed, or somehow never recorded — in every
            // case, generating or sending anything here would risk a
            // duplicate reply.
            return;
        }

        try {
            $sent = $this->process($ai, $credit, $api, $style, $knowledge, $products, $memory, $emotion, $transcription, $handoff, $memories, $productImages, $postPurchase, $batchIds);

            if ($sent) {
                AiAgentMessageJob::markCompletedBatch($this->tenantId, $batchIds);
            } else {
                // process() returned early without throwing — tenant/
                // message no longer eligible, AI got turned back off,
                // OpenAI failed, or the Messenger send failed. None of
                // these are exceptions (each is an expected, already-
                // logged-if-relevant outcome), but none of them sent a
                // reply either, so this is not 'completed'.
                AiAgentMessageJob::markFailedBatch($this->tenantId, $batchIds);
            }
        } catch (\Throwable $e) {
            AiAgentMessageJob::markFailedBatch($this->tenantId, $batchIds);

            Log::warning('AI agent job: processing failed.', [
                'tenant_id' => $this->tenantId,
                'messenger_message_id' => $this->messengerMessageId,
                'exception' => get_class($e),
            ]);

            // Deliberately not rethrown: markFailedBatch() above already
            // makes any further Laravel-level retry of this job a safe
            // no-op via the claim guard (status is no longer 'pending'),
            // so there is nothing left for the framework's own retry/
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
    protected function process(AiAgentService $ai, AiCreditService $credit, MessengerApi $api, AiConversationStyleService $style, AiTenantKnowledgeService $knowledge, AiProductKnowledgeService $products, AiCustomerMemoryService $memory, AiCustomerEmotionService $emotion, AiAudioTranscriptionService $transcription, AiHandoffService $handoff, AiTenantMemoryService $memories, AiProductImageMemoryService $productImages, AiPostPurchaseContextService $postPurchase, array $batchIds = []): bool
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

        // Phase 14 — a super-admin-imposed platform pause, independent of
        // the tenant's own toggles above — see Tenant::isAiPaused()'s
        // docblock.
        if ($tenant->isAiPaused()) {
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

        if (! $message || $message->direction !== 'in') {
            return false;
        }

        $psid = $message->sender_psid;

        // Phase 13 — an unresolved handoff means a real staff member is
        // already handling this conversation; the AI must not auto-reply
        // again until they resolve it from the panel — see
        // AiHandoffService's docblock. Checked before any of the
        // (billable) work below, same "STOP without replying" posture as
        // the ai_agent_enabled toggle.
        if ($handoff->isActive($this->tenantId, 'messenger', $psid)) {
            return false;
        }

        // A genuine staff reply pauses the AI for ONLY this conversation
        // for MessengerMessage::HUMAN_PAUSE_MINUTES minutes — separate
        // from the handoff above (which is permanent until a staff member
        // explicitly resolves it): this lazily expires on its own once
        // the latest human reply falls outside the window, no action
        // required. See MessengerMessage::isHumanPaused()'s docblock.
        if (MessengerMessage::isHumanPaused($this->tenantId, $psid)) {
            return false;
        }

        // Phase 9 — an image attachment is now a valid reason to reply on
        // its own, even with no caption text at all; see
        // resolveImageUrl()'s docblock.
        $imageUrl = $this->resolveImageUrl($message);

        // Phase 10 — a voice message with no caption is transcribed to
        // text BEFORE anything else runs, so every downstream step
        // (product matching, style examples, generateReply() itself) just
        // sees ordinary message text — see transcribeAndPersist()'s
        // docblock for why this is never a second AI content-shape the
        // way images are. Only attempted once credit for it can actually
        // be recorded; a failed/unaffordable transcription degrades to
        // "nothing to reply to" exactly like a failed image resolution.
        $transcribedNow = false;

        if (! $message->message_text && $message->attachment_type === 'audio') {
            $transcript = $this->transcribeAndPersist($message, $transcription, $credit);

            if ($transcript !== null) {
                $message->message_text = $transcript;
                $transcribedNow = true;
            }
        }

        if (! $message->message_text && ! $imageUrl) {
            // Genuinely couldn't process this attachment (transcription
            // failed/unavailable, or the image URL never resolved) —
            // never hallucinate a reply. Hand off to a human for ONLY
            // this conversation; the isActive() check above will keep
            // silencing the AI here until a staff member resolves it,
            // without touching any other customer or tenant.
            if ($message->attachment_type === 'audio') {
                $handoff->trigger($this->tenantId, 'messenger', $psid, AiHandoffService::REASON_UNSUPPORTED_AUDIO, $message->id);
            } elseif ($message->attachment_type === 'image') {
                $handoff->trigger($this->tenantId, 'messenger', $psid, AiHandoffService::REASON_UNSUPPORTED_IMAGE, $message->id);
            }

            return false;
        }

        // Transcription is its own billable call — re-check the balance
        // it may have just spent before committing to the (separately
        // billable) chat completion below, the same "never trust an
        // earlier check is still true" posture every other precondition
        // in this method already follows.
        if ($transcribedNow && ! $credit->hasCredit($this->tenantId)) {
            return false;
        }

        // Part 12 — message coalescing. When $batchIds has more than
        // just this message (a rapid burst of fragments arrived within
        // the debounce window), combine them into one logical customer
        // turn — see combinedCustomerText()'s docblock. For the common,
        // non-bursty case (batch of exactly this one message) this is
        // byte-identical to (string) $message->message_text, so nothing
        // about single-message turns changes.
        $combinedText = $this->combinedCustomerText($batchIds, $message);

        // Phase 13 — deterministic, high-precision phrase match on
        // whatever text this turn ends up with (post-transcription,
        // post-coalescing) — never AI-decided, see AiHandoffService's
        // docblock. isActive() above already confirmed no handoff exists
        // yet for this conversation, so trigger() here always creates a
        // fresh row.
        $justTriggeredHandoff = $handoff->customerRequestedHuman($combinedText);

        if ($justTriggeredHandoff) {
            $handoff->trigger($this->tenantId, 'messenger', $psid, AiHandoffService::REASON_CUSTOMER_REQUESTED, $message->id);
        }

        // The history boundary must exclude EVERY message in this
        // coalesced batch, not just the triggering one — otherwise an
        // earlier fragment of the same turn would appear twice: once
        // folded into $combinedText and once again as if it were a prior
        // turn in $history.
        $historyBoundaryId = $batchIds === [] ? $message->id : min($batchIds);
        $history = $this->recentHistory($this->tenantId, $psid, $historyBoundaryId);

        // "AI মেমোরী" voice answers — a confident match is sent directly
        // via the existing Messenger attachment-send path, entirely
        // bypassing OpenAI (zero extra AI cost) — see
        // AiTenantMemoryService::bestAudioMatch()'s docblock.
        $audioMemory = $memories->bestAudioMatch(
            $this->tenantId,
            [...array_column($history, 'content'), $combinedText]
        );

        if ($audioMemory) {
            return $this->sendAudioMemoryReply($audioMemory, $psid, $api);
        }

        // "পণ্যের ছবি" — resolved the same way, BEFORE any OpenAI call,
        // deterministically — see AiProductImageMemoryService::resolve()'s
        // docblock for the two-stage matching and
        // AiImageRequestResolution's docblock for what each outcome means.
        $imageResolution = $productImages->resolve(
            $this->tenantId,
            $combinedText,
            array_column($history, 'content')
        );

        if ($imageResolution->isClarify()) {
            return $this->sendImageClarificationReply($psid, $api);
        }

        if ($imageResolution->isSendAndStop()) {
            return $this->sendProductImageReply($imageResolution->image, $psid, $api);
        }

        if ($imageResolution->isSendAndContinue()) {
            // The image is sent as a plain attachment (no caption) here;
            // the customer's other question(s) still get a real text
            // answer from the normal OpenAI flow below — see
            // AiImageRequestResolution's sendAndContinue() docblock for
            // why this is "one text reply plus necessary media," never
            // two text replies.
            $this->sendProductImageAttachmentOnly($imageResolution->image, $psid, $api);
        }

        $styleExamples = $style->messengerStyleExamples($this->tenantId);
        // Same "not just this row" resolution MessengerInboxController
        // already relies on — a name resolved on an earlier message in
        // this same conversation is still the best one known, even if
        // this particular inbound message didn't carry it.
        $customerName = MessengerMessage::resolvedNameFor($this->tenantId, $psid);
        $tenantInstructions = $this->tenantInstructions($this->tenantId);
        $businessKnowledge = $knowledge->businessKnowledge($this->tenantId);
        // Search the current message plus recent history for a literal
        // mention of one of this tenant's own product names — see
        // AiProductKnowledgeService's docblock for why this is a cheap
        // deterministic string match, never a second AI call.
        $productData = $products->relevantProducts(
            $this->tenantId,
            [...array_column($history, 'content'), $combinedText]
        );
        // "Teach Your AI Agent" — best-matching saved Q&A for this exact
        // message (see AiTenantMemoryService's docblock for the cheap
        // keyword-overlap matching, never every saved memory).
        $tenantMemories = $memories->relevantMemories(
            $this->tenantId,
            [...array_column($history, 'content'), $combinedText]
        );
        // Phase 6 — keyed only by this exact psid (channel-verified, never
        // customer-typed) — see AiCustomerMemoryService's docblock.
        $customerMemory = $memory->forMessengerCustomer($this->tenantId, $psid);
        // Generic, category-agnostic complaint/post-purchase-concern
        // detection — see AiPostPurchaseContextService's docblock. Only
        // ever looks this customer's own real order_items, never assumes
        // a purchase happened when nothing verifies it.
        $postPurchaseConcernContext = null;

        if ($postPurchase->isPostPurchaseConcern($combinedText)) {
            $postPurchaseConcernContext = $postPurchase->forMessengerCustomer(
                $this->tenantId,
                $psid,
                [...array_column($history, 'content'), $combinedText]
            ) ?? 'No verified purchase record was found for the specific product this customer seems to be referring to — do not assume or claim they purchased it; ask a brief clarifying question instead if genuinely needed.';
        }
        // Phase 8 — a verified elapsed-wait fact, never a guessed mood —
        // see AiCustomerEmotionService's docblock.
        $customerEmotion = $emotion->forMessengerCustomer($this->tenantId, $psid, $message->id);
        // Phase 13 — only set on the exact turn the handoff was just
        // created above, so this is a genuinely true fact at the moment
        // the model is told about it — see AiHandoffService's docblock.
        $handoffNotice = $justTriggeredHandoff
            ? 'The customer just asked to speak with a real person, so this conversation has been flagged for your team to take over from here.'
            : null;

        $result = $ai->generateReply($tenant->store_name, $history, $combinedText, $styleExamples, $customerName, $tenantInstructions, $businessKnowledge, $productData, $customerMemory, $customerEmotion, $imageUrl, $handoffNotice, $tenantMemories, $postPurchaseConcernContext);

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

        // Phase 12 — best-effort "Business is typing..." indicator, then a
        // bounded human-feeling delay, both AFTER credit has already been
        // recorded above so neither can affect billing — see
        // humanDelay()'s docblock.
        try {
            $api->sendTypingOn($psid, $token);
        } catch (\Throwable $e) {
            // Purely cosmetic — never let a failure here block the actual
            // reply below.
        }

        $this->humanDelay();

        // Phase 18 — re-check, not the isActive() call made before
        // generation above: the OpenAI round-trip plus humanDelay() can
        // together span several seconds, long enough for the customer to
        // ask for a human or a staff member to take over from the panel
        // after this job's snapshot was taken but before it actually
        // sends. Credit was already recorded above regardless (the OpenAI
        // cost was genuinely incurred), but the generated reply itself
        // must never reach the customer once a handoff exists — see
        // AiHandoffService::isActive()'s docblock.
        if ($handoff->isActive($this->tenantId, 'messenger', $psid)) {
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
        $attributes = [
            'tenant_id' => $this->tenantId,
            'sender_psid' => $psid,
            'mid' => $sendResult['message_id'] ?? null,
            'message_text' => $reply,
            'direction' => 'out',
            'status' => 'contacted',
        ];

        // sent_by='ai' is what keeps AiConversationStyleService's human-only
        // style examples from ever learning from the AI's own past replies
        // — see database/sql/chunk36.sql's docblock.
        if (MessengerMessage::sentByColumnReady()) {
            $attributes['sent_by'] = 'ai';
        }

        MessengerMessage::withoutGlobalScopes()->create($attributes);

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
     * Phase 3 — free-text tenant behavior instructions (Tenant\SettingController
     * ::aiAgent(), store_settings key ai_custom_instructions). Empty string
     * (no row, or saved blank) normalizes to null so AiAgentService can do
     * a simple truthy check rather than every caller re-checking for ''.
     */
    protected function tenantInstructions(int $tenantId): ?string
    {
        $value = StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'ai_custom_instructions')
            ->value('value');

        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * Minimal, safe context: only this conversation's own recent
     * messages, nothing from any other table. No tools, no arbitrary
     * database access — see AiAgentService's docblock.
     *
     * Deliberately no longer filters out attachment-only turns (no
     * caption text) — a customer who sends a photo with no caption used
     * to vanish from history entirely, so a later "দাম কত?" carried no
     * trace an image was ever shared. A short placeholder line keeps that
     * turn in context (see attachmentPlaceholder()) without pretending the
     * AI actually understood the attachment's contents — real image/audio
     * understanding is a separate, not-yet-built capability.
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
            ->where(fn ($q) => $q->whereNotNull('message_text')->orWhereNotNull('attachment_url'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (MessengerMessage $m) => [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => $m->message_text !== null && $m->message_text !== ''
                    ? (string) $m->message_text
                    : $this->attachmentPlaceholder($m->attachment_type),
            ])
            ->all();
    }

    /**
     * A short, honest stand-in for an attachment-only history turn —
     * never claims the AI understood what was sent, only that something
     * was sent, so the model doesn't silently lose track of the turn
     * existing at all. attachment_type is Meta's own raw webhook value
     * (image/audio/video/file/...); anything unrecognized degrades to a
     * generic "sent an attachment" line rather than guessing.
     */
    protected function attachmentPlaceholder(?string $attachmentType): string
    {
        return match ($attachmentType) {
            'image' => '[customer sent a photo]',
            'audio' => '[customer sent a voice message]',
            'video' => '[customer sent a video]',
            default => '[customer sent an attachment]',
        };
    }

    /**
     * Part 12 — message coalescing. Joins every message in this batch
     * into one logical customer turn, oldest-first, exactly the way a
     * human reading the fragments in order would piece the sentence back
     * together ("আমার" + "স্কিনে এখন" + "লালচে" + "দাগ" -> "আমার স্কিনে
     * এখন লালচে দাগ"). $triggeringMessage's OWN text is read from the
     * in-memory object (never re-queried) so a transcript written by
     * transcribeAndPersist() moments earlier is always reflected.
     *
     * A batch of exactly one message (the overwhelming common case —
     * most turns aren't part of a rapid burst) returns
     * (string) $triggeringMessage->message_text completely unchanged
     * from how this job behaved before coalescing existed — no trimming,
     * no placeholder substitution — so nothing about a normal single-
     * message turn is altered by this method existing.
     *
     * For an earlier batch member that has NO text (an image/audio
     * attachment with no caption, arriving moments before a text
     * fragment), this folds in the same honest attachmentPlaceholder()
     * already used for history turns, rather than silently dropping it —
     * but only resolveImageUrl()/transcribeAndPersist() acting on
     * $triggeringMessage actually analyze an attachment's contents; an
     * attachment on a NON-triggering batch member is acknowledged in
     * text, never visually/audibly understood. Combining rapid bursts of
     * pure text is this feature's actual target (see the spec example
     * above); a burst that also mixes in media is a rarer edge case.
     *
     * @param  array<int, int>  $batchIds  Ascending, from
     *                                     AiAgentMessageJob::coalescedBatchIds() — always includes
     *                                     $triggeringMessage->id.
     */
    protected function combinedCustomerText(array $batchIds, MessengerMessage $triggeringMessage): string
    {
        if (count($batchIds) <= 1) {
            return (string) $triggeringMessage->message_text;
        }

        $others = MessengerMessage::withoutGlobalScopes()
            ->whereIn('id', array_diff($batchIds, [$triggeringMessage->id]))
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $parts = [];

        foreach ($batchIds as $id) {
            $m = $id === $triggeringMessage->id ? $triggeringMessage : $others->get($id);

            if (! $m) {
                continue; // e.g. deleted between claim and this read — skip rather than fail the whole turn
            }

            $text = $m->message_text;

            if ($text !== null && trim($text) !== '') {
                $parts[] = trim($text);
            } elseif ($m->attachment_type) {
                $parts[] = $this->attachmentPlaceholder($m->attachment_type);
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Phase 9 — only ever resolves an image on the CURRENT message being
     * replied to, never on older history turns (those stay text-only
     * placeholders via attachmentPlaceholder() above) — re-analyzing every
     * past image on every subsequent reply would be unbounded, repeated
     * AI cost for no real benefit. Messenger attachments are already
     * rehosted to our own public storage at webhook time
     * (MessengerWebhookController::rehostAttachment()), so this is a
     * plain column read, never a new HTTP fetch — the returned URL is
     * passed straight into AiAgentService::generateReply(), which builds
     * the OpenAI vision-capable message shape from it.
     */
    protected function resolveImageUrl(MessengerMessage $message): ?string
    {
        if ($message->attachment_type !== 'image' || ! $message->attachment_url) {
            return null;
        }

        return $message->attachment_url;
    }

    /**
     * Phase 10 — only ever transcribes the CURRENT message (same "never
     * re-analyze old history" reasoning as resolveImageUrl()). Unlike an
     * image, a voice message's attachment_url already IS a plain, already
     * public rehosted file (same MessengerWebhookController::
     * rehostAttachment() as any other Messenger attachment) — this
     * downloads those bytes (transcription needs the actual file, not a
     * link the way vision's image_url does) and hands them to
     * AiAudioTranscriptionService.
     *
     * On success, writes the transcript back onto this exact message row
     * (message_text) so it (a) becomes this reply's customer message with
     * zero further plumbing, and (b) is available as real text — not just
     * the "[customer sent a voice message]" placeholder — the next time
     * this conversation's history is replayed, without ever transcribing
     * the same audio twice. On ANY failure (download, transcription, an
     * empty result), returns null and leaves the row untouched — no
     * credit is charged (see AiCreditService::recordTranscriptionUsage()'s
     * docblock) and the placeholder stays honest.
     */
    protected function transcribeAndPersist(MessengerMessage $message, AiAudioTranscriptionService $transcription, AiCreditService $credit): ?string
    {
        if (! $message->attachment_url) {
            return null;
        }

        try {
            $audio = Http::timeout(20)->get($message->attachment_url);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $audio->successful()) {
            return null;
        }

        $body = $audio->body();

        // OpenAI's own transcription file-size ceiling.
        if (strlen($body) > 25 * 1024 * 1024) {
            return null;
        }

        $result = $transcription->transcribe($body, $audio->header('Content-Type', 'audio/ogg'));

        if (! $result) {
            return null;
        }

        $credit->recordTranscriptionUsage(
            $this->tenantId,
            $result['durationSeconds'],
            (string) config('ai.transcription_model', 'whisper-1'),
            contextType: 'messenger_voice_transcription',
            contextId: $message->id,
        );

        MessengerMessage::withoutGlobalScopes()->where('id', $message->id)->update(['message_text' => $result['text']]);

        return $result['text'];
    }

    /**
     * "AI মেমোরী" voice answers — sends the tenant's own recorded/
     * uploaded clip verbatim via the same attachment-send path a human
     * staff reply already uses (MessengerInboxController::reply()'s
     * image-send branch), never through OpenAI. Records the outbound
     * MessengerMessage row the same way a normal AI text reply does, so
     * it appears in the inbox and the mid-based webhook dedup already
     * finds it — see the bottom of process() for the text-reply
     * counterpart this mirrors.
     */
    protected function sendAudioMemoryReply(AiTenantMemory $audioMemory, string $psid, MessengerApi $api): bool
    {
        $token = $this->resolveOutboundToken($this->tenantId, $psid);

        if (! $token) {
            return false;
        }

        try {
            $api->sendTypingOn($psid, $token);
        } catch (\Throwable $e) {
            // Purely cosmetic — never let a failure here block the actual send.
        }

        $this->humanDelay();

        $url = asset('storage/'.$audioMemory->answer_audio_path);
        $sendResult = $api->sendAttachment($psid, $url, 'audio', $token);

        if (isset($sendResult['error'])) {
            Log::warning('AI agent job: saved voice-memory Messenger send failed.', [
                'tenant_id' => $this->tenantId,
                'error_type' => $sendResult['error']['type'] ?? null,
            ]);

            return false;
        }

        $attributes = [
            'tenant_id' => $this->tenantId,
            'sender_psid' => $psid,
            'mid' => $sendResult['message_id'] ?? null,
            'message_text' => null,
            'attachment_url' => $url,
            'attachment_type' => 'audio',
            'direction' => 'out',
            'status' => 'contacted',
        ];

        if (MessengerMessage::sentByColumnReady()) {
            $attributes['sent_by'] = 'ai';
        }

        MessengerMessage::withoutGlobalScopes()->create($attributes);

        return true;
    }

    /**
     * "পণ্যের ছবি" — a confident, unambiguous image match where the
     * message asked for nothing else. Sends the stored image plus one
     * short canned caption, via the same attachment-send path a human
     * staff reply already uses — entirely bypassing OpenAI, zero AI
     * credit deducted (mirrors sendAudioMemoryReply() above).
     */
    protected function sendProductImageReply(TenantProductImage $productImage, string $psid, MessengerApi $api): bool
    {
        $token = $this->resolveOutboundToken($this->tenantId, $psid);

        if (! $token) {
            return false;
        }

        if (! $this->sendProductImageAttachmentOnly($productImage, $psid, $api, $token)) {
            return false;
        }

        $caption = self::PRODUCT_IMAGE_CAPTIONS[$productImage->id % count(self::PRODUCT_IMAGE_CAPTIONS)];
        $captionResult = $api->sendMessage($psid, $caption, $token);

        if (isset($captionResult['error'])) {
            Log::warning('AI agent job: product-image caption Messenger send failed.', [
                'tenant_id' => $this->tenantId,
                'error_type' => $captionResult['error']['type'] ?? null,
            ]);

            // The image itself already reached the customer — a failed
            // one-line caption afterward doesn't undo that, so this turn
            // still counts as a successful reply.
            return true;
        }

        $attributes = [
            'tenant_id' => $this->tenantId,
            'sender_psid' => $psid,
            'mid' => $captionResult['message_id'] ?? null,
            'message_text' => $caption,
            'direction' => 'out',
            'status' => 'contacted',
        ];

        if (MessengerMessage::sentByColumnReady()) {
            $attributes['sent_by'] = 'ai';
        }

        MessengerMessage::withoutGlobalScopes()->create($attributes);

        return true;
    }

    /**
     * "পণ্যের ছবি" — sends just the image attachment, no caption text.
     * Used both by sendProductImageReply() above and directly by
     * process() for the sendAndContinue case, where the upcoming normal
     * OpenAI text reply is what addresses the rest of the customer's
     * message — see AiImageRequestResolution::sendAndContinue()'s
     * docblock.
     */
    protected function sendProductImageAttachmentOnly(TenantProductImage $productImage, string $psid, MessengerApi $api, ?string $token = null): bool
    {
        $token ??= $this->resolveOutboundToken($this->tenantId, $psid);

        if (! $token) {
            return false;
        }

        try {
            $api->sendTypingOn($psid, $token);
        } catch (\Throwable $e) {
            // Purely cosmetic — never let a failure here block the actual send.
        }

        $this->humanDelay();

        $url = asset('storage/'.$productImage->image_path);
        $sendResult = $api->sendAttachment($psid, $url, 'image', $token);

        if (isset($sendResult['error'])) {
            Log::warning('AI agent job: saved product-image Messenger send failed.', [
                'tenant_id' => $this->tenantId,
                'error_type' => $sendResult['error']['type'] ?? null,
            ]);

            return false;
        }

        $attributes = [
            'tenant_id' => $this->tenantId,
            'sender_psid' => $psid,
            'mid' => $sendResult['message_id'] ?? null,
            'message_text' => null,
            'attachment_url' => $url,
            'attachment_type' => 'image',
            'direction' => 'out',
            'status' => 'contacted',
        ];

        if (MessengerMessage::sentByColumnReady()) {
            $attributes['sent_by'] = 'ai';
        }

        MessengerMessage::withoutGlobalScopes()->create($attributes);

        return true;
    }

    /**
     * "পণ্যের ছবি" — two or more saved images are comparably plausible
     * for this image request (AiImageRequestResolution::clarify()). Sends
     * one short, deterministic clarifying question — never OpenAI, never
     * a guessed image — see AiProductImageMemoryService::pickWinner()'s
     * docblock.
     */
    protected function sendImageClarificationReply(string $psid, MessengerApi $api): bool
    {
        $token = $this->resolveOutboundToken($this->tenantId, $psid);

        if (! $token) {
            return false;
        }

        $reply = 'অবশ্যই 😊 কোন পণ্যটির ছবি চান?';
        $sendResult = $api->sendMessage($psid, $reply, $token);

        if (isset($sendResult['error'])) {
            Log::warning('AI agent job: image-clarification Messenger send failed.', [
                'tenant_id' => $this->tenantId,
                'error_type' => $sendResult['error']['type'] ?? null,
            ]);

            return false;
        }

        $attributes = [
            'tenant_id' => $this->tenantId,
            'sender_psid' => $psid,
            'mid' => $sendResult['message_id'] ?? null,
            'message_text' => $reply,
            'direction' => 'out',
            'status' => 'contacted',
        ];

        if (MessengerMessage::sentByColumnReady()) {
            $attributes['sent_by'] = 'ai';
        }

        MessengerMessage::withoutGlobalScopes()->create($attributes);

        return true;
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

    /**
     * Phase 12 — response humanization. An AI reply that always arrives
     * at a fixed, sub-second latency — no matter the hour, no matter how
     * involved the question — is itself one of the most obvious "this is
     * a bot" tells, independent of how good the reply text itself is
     * (Phase 7's style learning already handles that half). This sleeps
     * the current job for a few real seconds before the send below, which
     * is safe here specifically because credit was already deducted
     * above and this runs well inside both the job's own $timeout and the
     * cron worker's --max-time budget (see this class's own docblock).
     *
     * Deliberately bounded to a handful of seconds, not a realistic full
     * typing-time simulation (a genuinely long, reply-length-proportional
     * delay would need generation and delivery split into two separately
     * -dispatched queued jobs — a larger architectural change, not taken
     * here). Skipped entirely under phpunit so the test suite never
     * actually sleeps; humanDelaySeconds() itself stays directly testable
     * since it has no other observable side effect.
     */
    protected function humanDelay(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        sleep($this->humanDelaySeconds());
    }

    public function humanDelaySeconds(): int
    {
        $min = max(0, (int) config('ai.human_delay_min_seconds', 2));
        $max = max($min, (int) config('ai.human_delay_max_seconds', 6));

        return random_int($min, $max);
    }
}
