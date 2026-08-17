<?php

namespace App\Services\AI;

use App\Models\MessengerMessage;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8 — "customer emotion", scoped deliberately narrower than the name
 * might suggest. This does NOT run keyword/sentiment matching ("বাজে",
 * "angry", "!!!", ALL CAPS, ...) to guess a mood from the message text —
 * config('ai.system_prompt')'s own "Reading the customer's emotion"
 * section already instructs the model to read tone from the full
 * conversation it's given (Phase 2), which is a strictly better judge of
 * real tone/sarcasm/context than a regex ever would be. Duplicating that
 * with a cheap heuristic would risk actively misleading the model with a
 * confidently-labeled but often-wrong guess — exactly what "never invent"
 * warns against, just applied to a feeling instead of a fact.
 *
 * What the model genuinely CANNOT see, though, is elapsed time and reply
 * history — App\Jobs\ProcessAiAgentMessage::recentHistory() (and its
 * WhatsApp counterpart) strip timestamps entirely, so a customer who has
 * sent three follow-ups in a row with zero reply looks, in the prompt,
 * identical to one asking a first question. That gap is a real, VERIFIED
 * fact (computed from the same message timestamps already sitting in the
 * table, one extra cheap bounded query — no new infrastructure, no
 * second AI call, no charge), not a guess, so it is safe to state
 * directly the same way AiCustomerMemoryService's order data is: only
 * ever a count and an elapsed duration, never a mood label like
 * "frustrated" or "angry" invented on top of it.
 */
class AiCustomerEmotionService
{
    public function forMessengerCustomer(int $tenantId, string $psid, int $beforeMessageId): string
    {
        return $this->describe($this->waitSignal(function () use ($tenantId, $psid, $beforeMessageId) {
            return MessengerMessage::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sender_psid', $psid)
                ->where('id', '<', $beforeMessageId)
                ->orderByDesc('id')
                ->limit(6)
                ->get(['direction', 'created_at']);
        }, $tenantId));
    }

    public function forWhatsAppCustomer(int $tenantId, string $waId, int $beforeMessageId): string
    {
        return $this->describe($this->waitSignal(function () use ($tenantId, $waId, $beforeMessageId) {
            return WhatsAppMessage::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('wa_id', $waId)
                ->where('id', '<', $beforeMessageId)
                ->orderByDesc('id')
                ->limit(6)
                ->get(['direction', 'created_at']);
        }, $tenantId));
    }

    /**
     * @param  \Closure(): Collection  $fetch
     * @return array{count: int, since: Carbon}|null
     */
    protected function waitSignal(\Closure $fetch, int $tenantId): ?array
    {
        try {
            $recent = $fetch();
        } catch (\Throwable $e) {
            Log::warning('AI customer emotion: failed to look up recent message history — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return null;
        }

        // Most-recent-first: count how many rows in a row (starting from
        // the top) are inbound, stopping at the first outbound one — that
        // run is every prior message that never got a reply. +1 accounts
        // for the current inbound message itself, which triggered this
        // call and is always unanswered by definition.
        $unansweredBefore = 0;
        $oldestUnanswered = null;

        foreach ($recent as $row) {
            if ($row->direction !== 'in') {
                break;
            }

            $unansweredBefore++;
            $oldestUnanswered = $row->created_at;
        }

        // A single unanswered message (just the current one) is normal,
        // not a wait signal — only surface this once the customer has
        // genuinely sent a follow-up with nothing back yet.
        if ($unansweredBefore < 1 || ! $oldestUnanswered) {
            return null;
        }

        return ['count' => $unansweredBefore + 1, 'since' => Carbon::parse($oldestUnanswered)];
    }

    /**
     * Deliberately just the raw fact, no safety framing — AiAgentService::
     * systemPrompt() owns wrapping every one of these optional sections
     * with its own explanatory/boundary text, the same way it does for
     * $productData/$businessKnowledge/$customerMemory; this service stays
     * a plain fact source, consistent with that pattern.
     */
    protected function describe(?array $signal): string
    {
        if (! $signal) {
            return '';
        }

        $duration = $this->formatDuration($signal['since']);

        return "This customer has sent {$signal['count']} messages in a row without a reply yet, waiting since about {$duration} ago.";
    }

    protected function formatDuration(Carbon $since): string
    {
        $minutes = max(0, (int) $since->diffInMinutes(now()));

        return match (true) {
            $minutes < 60 => max(1, $minutes).' minute(s)',
            $minutes < 1440 => intdiv($minutes, 60).' hour(s)',
            default => intdiv($minutes, 1440).' day(s)',
        };
    }
}
