<?php

namespace App\Services\AI;

use App\Models\AiHandoff;
use Illuminate\Support\Facades\Log;

/**
 * Phase 13 — the real human handoff mechanism config('ai.system_prompt')'s
 * "never claim a human was notified" rule has been waiting for since
 * Phase 1. See database/sql/chunk38.sql's docblock for the schema
 * reasoning; this service is the ONLY thing that reads/writes that table.
 *
 * Deliberately NOT AI-decided: whether to hand off is resolved
 * deterministically by the caller (App\Jobs\ProcessAiAgentMessage /
 * ProcessWhatsAppAiAgentMessage) BEFORE AiAgentService is ever called —
 * the same "trust deterministic Laravel code, not a free-text model
 * output, for anything that gates behavior" principle every other phase
 * in this project already follows (see AiCustomerEmotionService's
 * docblock for the same reasoning applied to mood). The AI's only role is
 * to be told, as a verified fact, that a handoff it did NOT decide has
 * already genuinely happened, and to compose one honest closing reply
 * around that fact.
 *
 * customerRequestedHuman()'s phrase list is deliberately narrow and
 * multi-word/specific rather than single ambiguous words — a bare
 * "human" would false-positive on a real product query like "Human hair
 * extension আছে?" (a genuine product category), so every phrase here is
 * specific enough that it essentially only appears when someone is
 * actually asking for a person. Missing a real request is an acceptable
 * cost for never falsely escalating (and silencing the AI for) an
 * ordinary product question.
 */
class AiHandoffService
{
    public const REASON_CUSTOMER_REQUESTED = 'customer_requested';

    protected const REQUEST_PHRASES = [
        'talk to a human', 'speak to a human', 'human agent', 'live agent',
        'talk to a person', 'speak to a person', 'talk to someone', 'speak to someone',
        'talk to staff', 'speak to staff', 'human support', 'real person please',
        'মানুষের সাথে কথা বলতে চাই', 'একজন মানুষের সাথে কথা বলতে চাই',
        'সরাসরি মানুষের সাথে কথা বলতে চাই', 'এজেন্টের সাথে কথা বলতে চাই',
        'স্টাফের সাথে কথা বলতে চাই', 'কাস্টমার কেয়ারে কথা বলতে চাই',
        'হিউম্যান সাপোর্ট চাই', 'রিয়েল মানুষের সাথে কথা বলতে চাই',
    ];

    /**
     * True once an unresolved handoff exists for this exact conversation —
     * the caller must stop auto-replying entirely when this is true, same
     * "STOP without replying" posture as the ai_agent_enabled toggle.
     */
    public function isActive(int $tenantId, string $channel, string $externalId): bool
    {
        if (! AiHandoff::tablesReady()) {
            return false;
        }

        try {
            return AiHandoff::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('channel', $channel)
                ->where('external_id', $externalId)
                ->whereNull('resolved_at')
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('AI handoff: isActive() check failed — treating as not active.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return false;
        }
    }

    /** Deterministic, high-precision phrase match — see class docblock. */
    public function customerRequestedHuman(?string $messageText): bool
    {
        if (! $messageText || trim($messageText) === '') {
            return false;
        }

        $haystack = mb_strtolower($messageText);

        foreach (self::REQUEST_PHRASES as $phrase) {
            if (str_contains($haystack, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Creates the handoff row unless one is already active for this
     * conversation (never duplicates an ongoing handoff). Must be called
     * BEFORE AiAgentService::generateReply() for the resulting fact to be
     * genuinely true when the model is told about it — see class
     * docblock.
     */
    public function trigger(int $tenantId, string $channel, string $externalId, string $reason, ?int $messageId = null): void
    {
        if (! AiHandoff::tablesReady() || $this->isActive($tenantId, $channel, $externalId)) {
            return;
        }

        try {
            AiHandoff::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'channel' => $channel,
                'external_id' => $externalId,
                'reason' => $reason,
                'triggered_by_message_id' => $messageId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI handoff: failed to create a handoff row.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);
        }
    }

    /**
     * Explicit staff action only (Tenant\MessengerInboxController::resumeAi()/
     * WhatsAppInboxController::resumeAi()) — a human reply sent through
     * the panel does NOT implicitly resolve a handoff, so a staff member
     * can keep replying manually without the AI silently jumping back in
     * mid-conversation. Resolves every currently-unresolved row for this
     * conversation (normally just one, since trigger() never duplicates).
     */
    public function resolve(int $tenantId, string $channel, string $externalId, int $userId): void
    {
        if (! AiHandoff::tablesReady()) {
            return;
        }

        AiHandoff::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by_user_id' => $userId]);
    }
}
