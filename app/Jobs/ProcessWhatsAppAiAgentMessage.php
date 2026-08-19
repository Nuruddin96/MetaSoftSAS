<?php

namespace App\Jobs;

use App\Models\AiTenantMemory;
use App\Models\AiWhatsAppMessageJob;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\TenantProductImage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\AI\AiAgentService;
use App\Services\AI\AiAudioTranscriptionService;
use App\Services\AI\AiConversationStyleService;
use App\Services\AI\AiCreditService;
use App\Services\AI\AiCustomerEmotionService;
use App\Services\AI\AiCustomerMemoryService;
use App\Services\AI\AiHandoffService;
use App\Services\AI\AiProductImageMemoryService;
use App\Services\AI\AiProductKnowledgeService;
use App\Services\AI\AiTenantKnowledgeService;
use App\Services\AI\AiTenantMemoryService;
use App\Services\WhatsApp\WhatsAppMediaService;
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

    /** Mirrors ProcessAiAgentMessage::PRODUCT_IMAGE_CAPTIONS — see its docblock. */
    protected const PRODUCT_IMAGE_CAPTIONS = [
        'এই যে ছবিটা 😊',
        'অবশ্যই, ছবি পাঠালাম 😊',
        'নিন, ছবিটা দেখে নিন 😊',
    ];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $whatsAppMessageId,
    ) {}

    public function handle(AiAgentService $ai, AiCreditService $credit, WhatsAppSendService $whatsapp, AiTenantKnowledgeService $knowledge, AiProductKnowledgeService $products, AiCustomerMemoryService $memory, AiConversationStyleService $style, AiCustomerEmotionService $emotion, WhatsAppMediaService $media, AiAudioTranscriptionService $transcription, AiHandoffService $handoff, AiTenantMemoryService $memories, AiProductImageMemoryService $productImages): void
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
            $sent = $this->process($ai, $credit, $whatsapp, $knowledge, $products, $memory, $style, $emotion, $media, $transcription, $handoff, $memories, $productImages);

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
    protected function process(AiAgentService $ai, AiCreditService $credit, WhatsAppSendService $whatsapp, AiTenantKnowledgeService $knowledge, AiProductKnowledgeService $products, AiCustomerMemoryService $memory, AiConversationStyleService $style, AiCustomerEmotionService $emotion, WhatsAppMediaService $media, AiAudioTranscriptionService $transcription, AiHandoffService $handoff, AiTenantMemoryService $memories, AiProductImageMemoryService $productImages): bool
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

        // Phase 14 — a super-admin-imposed platform pause, independent of
        // the tenant's own toggles above — see Tenant::isAiPaused()'s
        // docblock.
        if ($tenant->isAiPaused()) {
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
        if (! $message || $message->direction !== 'in') {
            return false;
        }

        $waId = $message->wa_id;

        // Phase 13 — an unresolved handoff means a real staff member is
        // already handling this conversation; the AI must not auto-reply
        // again until they resolve it from the panel — see
        // AiHandoffService's docblock.
        if ($handoff->isActive($this->tenantId, 'whatsapp', $waId)) {
            return false;
        }

        // Phase 9 — an image attachment is now a valid reason to reply on
        // its own, even with no caption text at all; see
        // resolveImageUrl()'s docblock.
        $imageUrl = $this->resolveImageUrl($message, $media);

        // Phase 10 — a voice message with no caption is transcribed to
        // text BEFORE anything else runs — see ProcessAiAgentMessage::
        // transcribeAndPersist()'s docblock (this mirrors it one-for-one,
        // using WhatsAppMediaService instead of a plain HTTP fetch).
        $transcribedNow = false;

        if (! $message->message_text && $message->message_type === 'audio') {
            $transcript = $this->transcribeAndPersist($message, $media, $transcription, $credit);

            if ($transcript !== null) {
                $message->message_text = $transcript;
                $transcribedNow = true;
            }
        }

        if (! $message->message_text && ! $imageUrl) {
            return false;
        }

        // Transcription is its own billable call — re-check the balance
        // it may have just spent before committing to the (separately
        // billable) chat completion below.
        if ($transcribedNow && ! $credit->hasCredit($this->tenantId)) {
            return false;
        }

        // Phase 13 — deterministic, high-precision phrase match on
        // whatever text this turn ends up with (post-transcription) —
        // never AI-decided, see AiHandoffService's docblock. isActive()
        // above already confirmed no handoff exists yet for this
        // conversation, so trigger() here always creates a fresh row.
        $justTriggeredHandoff = $handoff->customerRequestedHuman($message->message_text);

        if ($justTriggeredHandoff) {
            $handoff->trigger($this->tenantId, 'whatsapp', $waId, AiHandoffService::REASON_CUSTOMER_REQUESTED, $message->id);
        }

        $history = $this->recentHistory($this->tenantId, $waId, $message->id);

        // "AI মেমোরী" voice answers — a confident match is sent directly
        // via the existing WhatsApp media-send path, entirely bypassing
        // OpenAI (zero extra AI cost) — see AiTenantMemoryService::
        // bestAudioMatch()'s docblock.
        $audioMemory = $memories->bestAudioMatch(
            $this->tenantId,
            [...array_column($history, 'content'), (string) $message->message_text]
        );

        if ($audioMemory) {
            return $this->sendAudioMemoryReply($audioMemory, $tenant, $waId, $whatsapp);
        }

        // "পণ্যের ছবি" — resolved the same way, BEFORE any OpenAI call,
        // deterministically — see AiProductImageMemoryService::resolve()'s
        // docblock and AiImageRequestResolution's docblock.
        $imageResolution = $productImages->resolve(
            $this->tenantId,
            (string) $message->message_text,
            array_column($history, 'content')
        );

        if ($imageResolution->isClarify()) {
            return $this->sendImageClarificationReply($tenant, $waId, $whatsapp);
        }

        if ($imageResolution->isSendAndStop()) {
            return $this->sendProductImageReply($imageResolution->image, $tenant, $waId, $whatsapp);
        }

        if ($imageResolution->isSendAndContinue()) {
            // Image sent as a plain attachment (no caption) here; the
            // customer's other question(s) still get a real text answer
            // from the normal OpenAI flow below — see
            // AiImageRequestResolution::sendAndContinue()'s docblock.
            $this->sendProductImageAttachmentOnly($imageResolution->image, $tenant, $waId, $whatsapp);
        }

        // Phase 7 — real historical human-written WhatsApp replies for this
        // tenant, same "sent_by='human' only" guarantee Messenger's style
        // learning already relies on — see
        // AiConversationStyleService::whatsappStyleExamples()'s docblock.
        $styleExamples = $style->whatsappStyleExamples($this->tenantId);
        $tenantInstructions = $this->tenantInstructions($this->tenantId);
        $businessKnowledge = $knowledge->businessKnowledge($this->tenantId);
        $productData = $products->relevantProducts(
            $this->tenantId,
            [...array_column($history, 'content'), $message->message_text]
        );
        // "Teach Your AI Agent" — best-matching saved Q&A for this exact
        // message, same shape Messenger's job resolves — see
        // AiTenantMemoryService's docblock.
        $tenantMemories = $memories->relevantMemories(
            $this->tenantId,
            [...array_column($history, 'content'), $message->message_text]
        );
        // Phase 6 — wa_id is Meta's own authenticated sender identity for
        // this webhook call, never a value the conversation text supplied
        // — see AiCustomerMemoryService's docblock.
        $customerMemory = $memory->forWhatsAppCustomer($this->tenantId, $waId);
        // Phase 8 — a verified elapsed-wait fact, never a guessed mood —
        // see AiCustomerEmotionService's docblock.
        $customerEmotion = $emotion->forWhatsAppCustomer($this->tenantId, $waId, $message->id);
        // Phase 13 — only set on the exact turn the handoff was just
        // created above, so this is a genuinely true fact at the moment
        // the model is told about it — see AiHandoffService's docblock.
        $handoffNotice = $justTriggeredHandoff
            ? 'The customer just asked to speak with a real person, so this conversation has been flagged for your team to take over from here.'
            : null;

        $result = $ai->generateReply($tenant->store_name, $history, (string) $message->message_text, styleExamples: $styleExamples, customerName: null, tenantInstructions: $tenantInstructions, businessKnowledge: $businessKnowledge, productData: $productData, customerMemory: $customerMemory, customerEmotion: $customerEmotion, imageUrl: $imageUrl, handoffNotice: $handoffNotice, tenantMemories: $tenantMemories);

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
        // sentBy: 'ai' is what keeps whatsappStyleExamples() from ever
        // learning from the AI's own past replies — see chunk37.sql.
        //
        // Phase 12 — bounded human-feeling delay before the send, AFTER
        // credit has already been recorded above so it can never affect
        // billing — see ProcessAiAgentMessage::humanDelay()'s docblock
        // (mirrored here one-for-one). No WhatsApp typing-indicator call
        // alongside it, unlike Messenger's sendTypingOn() — the Cloud
        // API's read-receipt/typing-indicator shape isn't reused
        // elsewhere in this codebase yet, so it's left for a later,
        // separately-verified addition rather than guessed at here.
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
        if ($handoff->isActive($this->tenantId, 'whatsapp', $waId)) {
            return false;
        }

        $sendResult = $whatsapp->sendText($tenant, $waId, $result['reply'], sentBy: 'ai');

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
     * Phase 3 — same free-text tenant instructions Messenger's job reads
     * (see ProcessAiAgentMessage::tenantInstructions()'s docblock) — one
     * business, one set of rules, shared across whichever channel the
     * customer happens to message on. Style examples/customer-name stay
     * Messenger-only (see class docblock's "reuses the same AiAgentService
     * text-only path" note), but business instructions are channel-agnostic.
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
     * messages, nothing from any other table — same shape as
     * ProcessAiAgentMessage::recentHistory(), reading whatsapp_messages/
     * wa_id instead of messenger_messages/sender_psid. No tools, no
     * arbitrary database access — see AiAgentService's docblock.
     *
     * Deliberately no longer filters out attachment-only turns — see
     * ProcessAiAgentMessage::recentHistory()'s docblock for why. WhatsApp
     * already stamps message_type on every row (unlike Messenger's
     * attachment_type, which is only ever set for the row that actually
     * carries one), so the placeholder reads straight off that column.
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
            ->where(fn ($q) => $q->whereNotNull('message_text')->orWhereNotNull('attachment_url'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (WhatsAppMessage $m) => [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => $m->message_text !== null && $m->message_text !== ''
                    ? (string) $m->message_text
                    : $this->attachmentPlaceholder($m->message_type),
            ])
            ->all();
    }

    /** Mirrors ProcessAiAgentMessage::attachmentPlaceholder() — see its docblock. */
    protected function attachmentPlaceholder(?string $messageType): string
    {
        return match ($messageType) {
            'image', 'sticker' => '[customer sent a photo]',
            'audio', 'voice' => '[customer sent a voice message]',
            'video' => '[customer sent a video]',
            'document' => '[customer sent a file]',
            'location' => '[customer shared a location]',
            default => '[customer sent an attachment]',
        };
    }

    /**
     * Phase 9 — WhatsApp counterpart of ProcessAiAgentMessage::
     * resolveImageUrl(), only ever for the CURRENT message (same "never
     * re-analyze old history" reasoning). Unlike Messenger, WhatsApp
     * attachments are never rehosted to a public URL (see
     * WhatsAppMediaService's own docblock for why — no confirmed
     * background worker on shared hosting, proxy-on-demand instead), so
     * this fetches the actual bytes through the same Cloud API path
     * Tenant\WhatsAppInboxController::media() already uses and inlines
     * them as a base64 data: URI — OpenAI's image_url.url field accepts
     * either a public URL or a data URI equally. Degrades to null (no
     * image, falls back to text-only) on ANY failure — missing token,
     * lookup/download failure, oversized body — never throws, and never
     * charges anything since this runs entirely before the OpenAI call.
     */
    protected function resolveImageUrl(WhatsAppMessage $message, WhatsAppMediaService $media): ?string
    {
        if ($message->message_type !== 'image') {
            return null;
        }

        $mediaId = $message->inboundMediaId();
        $token = $this->resolveInboundMediaAccessToken($this->tenantId);

        if (! $mediaId || ! $token) {
            return null;
        }

        $result = $media->fetch($mediaId, $token);

        if (! $result) {
            return null;
        }

        // Same sanity ceiling rehostAttachment() applies to Messenger
        // images — WhatsApp's own media limits keep real photos well under
        // this, this just guards against ever inlining something absurd
        // into the OpenAI request body.
        if (strlen($result['body']) > 20 * 1024 * 1024) {
            return null;
        }

        return 'data:'.$result['mimeType'].';base64,'.base64_encode($result['body']);
    }

    /**
     * "AI মেমোরী" voice answers — WhatsApp counterpart of
     * ProcessAiAgentMessage::sendAudioMemoryReply(), sending the tenant's
     * own recorded/uploaded clip verbatim, never through OpenAI.
     * WhatsAppSendService::sendMedia() persists the outbound
     * whatsapp_messages row itself (success or failure) — see its own
     * docblock — so this method must NOT create one too, same rule the
     * text-reply path below already follows.
     */
    protected function sendAudioMemoryReply(AiTenantMemory $audioMemory, Tenant $tenant, string $waId, WhatsAppSendService $whatsapp): bool
    {
        $this->humanDelay();

        $url = asset('storage/'.$audioMemory->answer_audio_path);
        $sendResult = $whatsapp->sendMedia($tenant, $waId, 'audio', $url, sentBy: 'ai');

        if (! $sendResult->successful) {
            Log::warning('WhatsApp AI agent job: saved voice-memory WhatsApp send failed.', [
                'tenant_id' => $this->tenantId,
                'error_code' => $sendResult->errorCode,
            ]);

            return false;
        }

        return true;
    }

    /**
     * "পণ্যের ছবি" — a confident, unambiguous image match where the
     * message asked for nothing else. Unlike Messenger's two-call shape
     * (ProcessAiAgentMessage::sendProductImageReply()), WhatsApp's Cloud
     * API accepts an image caption in the SAME send
     * (WhatsAppSendService::CAPTION_SUPPORTED_TYPES includes 'image'), so
     * this is one call, image + short canned caption together — entirely
     * bypassing OpenAI, zero AI credit deducted.
     */
    protected function sendProductImageReply(TenantProductImage $productImage, Tenant $tenant, string $waId, WhatsAppSendService $whatsapp): bool
    {
        $this->humanDelay();

        $url = asset('storage/'.$productImage->image_path);
        $caption = self::PRODUCT_IMAGE_CAPTIONS[$productImage->id % count(self::PRODUCT_IMAGE_CAPTIONS)];
        $sendResult = $whatsapp->sendMedia($tenant, $waId, 'image', $url, caption: $caption, sentBy: 'ai');

        if (! $sendResult->successful) {
            Log::warning('WhatsApp AI agent job: saved product-image WhatsApp send failed.', [
                'tenant_id' => $this->tenantId,
                'error_code' => $sendResult->errorCode,
            ]);

            return false;
        }

        return true;
    }

    /**
     * "পণ্যের ছবি" — sends just the image, no caption. Used by
     * sendAndContinue in process() above, where the upcoming normal
     * OpenAI text reply addresses the rest of the customer's message —
     * see AiImageRequestResolution::sendAndContinue()'s docblock.
     */
    protected function sendProductImageAttachmentOnly(TenantProductImage $productImage, Tenant $tenant, string $waId, WhatsAppSendService $whatsapp): bool
    {
        $this->humanDelay();

        $url = asset('storage/'.$productImage->image_path);
        $sendResult = $whatsapp->sendMedia($tenant, $waId, 'image', $url, sentBy: 'ai');

        if (! $sendResult->successful) {
            Log::warning('WhatsApp AI agent job: saved product-image WhatsApp send failed.', [
                'tenant_id' => $this->tenantId,
                'error_code' => $sendResult->errorCode,
            ]);

            return false;
        }

        return true;
    }

    /**
     * "পণ্যের ছবি" — two or more saved images are comparably plausible
     * (AiImageRequestResolution::clarify()). Sends one short,
     * deterministic clarifying question — never OpenAI, never a guessed
     * image — see AiProductImageMemoryService::pickWinner()'s docblock.
     */
    protected function sendImageClarificationReply(Tenant $tenant, string $waId, WhatsAppSendService $whatsapp): bool
    {
        $sendResult = $whatsapp->sendText($tenant, $waId, 'অবশ্যই 😊 কোন পণ্যটির ছবি চান?', sentBy: 'ai');

        if (! $sendResult->successful) {
            Log::warning('WhatsApp AI agent job: image-clarification WhatsApp send failed.', [
                'tenant_id' => $this->tenantId,
                'error_code' => $sendResult->errorCode,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Mirrors WhatsAppSendService::resolvePhoneNumber()'s exact query
     * shape — duplicated rather than shared for the same reason
     * resolveOutboundToken() in ProcessAiAgentMessage documents: this is a
     * different concern (reading inbound media, not sending) even though
     * the lookup happens to be identical today.
     */
    protected function resolveInboundMediaAccessToken(int $tenantId): ?string
    {
        return WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', 1)
            ->where('status', 'active')
            ->with('businessAccount')
            ->first()
            ?->businessAccount
            ?->user_access_token;
    }

    /**
     * Phase 10 — WhatsApp counterpart of ProcessAiAgentMessage::
     * transcribeAndPersist(), only ever for the CURRENT message. Reuses
     * the exact same media-fetch path resolveImageUrl() above already
     * uses (WhatsAppMediaService::fetch(), the same Cloud API proxy
     * Tenant\WhatsAppInboxController::media() uses) — raw bytes, not a
     * base64 data URI this time, since transcription needs a real file
     * upload, not an inline chat message part. On success, writes the
     * transcript back onto this exact message row so it becomes this
     * reply's customer message and is available as real text — not just
     * a placeholder — the next time this conversation's history is
     * replayed, without ever transcribing the same audio twice. On ANY
     * failure, returns null and leaves the row untouched — no credit
     * charged, see AiAudioTranscriptionService's docblock.
     */
    protected function transcribeAndPersist(WhatsAppMessage $message, WhatsAppMediaService $media, AiAudioTranscriptionService $transcription, AiCreditService $credit): ?string
    {
        $mediaId = $message->inboundMediaId();
        $token = $this->resolveInboundMediaAccessToken($this->tenantId);

        if (! $mediaId || ! $token) {
            return null;
        }

        $result = $media->fetch($mediaId, $token);

        if (! $result) {
            return null;
        }

        // OpenAI's own transcription file-size ceiling.
        if (strlen($result['body']) > 25 * 1024 * 1024) {
            return null;
        }

        $transcribed = $transcription->transcribe($result['body'], $result['mimeType']);

        if (! $transcribed) {
            return null;
        }

        $credit->recordTranscriptionUsage(
            $this->tenantId,
            $transcribed['durationSeconds'],
            (string) config('ai.transcription_model', 'whisper-1'),
            contextType: 'whatsapp_voice_transcription',
            contextId: $message->id,
        );

        WhatsAppMessage::withoutGlobalScopes()->where('id', $message->id)->update(['message_text' => $transcribed['text']]);

        return $transcribed['text'];
    }

    /** Mirrors ProcessAiAgentMessage::humanDelay() — see its docblock. */
    protected function humanDelay(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        sleep($this->humanDelaySeconds());
    }

    /** Mirrors ProcessAiAgentMessage::humanDelaySeconds() — see its docblock. */
    public function humanDelaySeconds(): int
    {
        $min = max(0, (int) config('ai.human_delay_min_seconds', 2));
        $max = max($min, (int) config('ai.human_delay_max_seconds', 6));

        return random_int($min, $max);
    }
}
