<?php

namespace App\Services\AI;

use App\Models\MessengerMessage;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Builds a small, tenant-specific "how does this business's own staff
 * actually talk" style profile from real conversation history — the fix
 * for a production incident where AI replies were technically correct but
 * read as a generic, over-formal call-center script instead of the
 * tenant's real human voice (see config('ai.system_prompt')'s docblock).
 * Covers both channels — messengerStyleExamples() (Messenger) and
 * whatsappStyleExamples() (Phase 7 — WhatsApp) — as two structurally
 * mirrored, independently-implemented halves of this one class, not a
 * shared query path, per this codebase's established "independent
 * implementation per channel" convention.
 *
 * ONLY ever learns from human-written outbound rows — messenger_messages/
 * whatsapp_messages with sent_by='human', never 'ai'. This is deliberate
 * and load-bearing: if the AI's own past replies were allowed into these
 * examples, it would learn from (and reinforce) whatever robotic phrasing
 * it already produces, defeating the entire point. See
 * database/sql/chunk36.sql (Messenger) / chunk37.sql (WhatsApp) and
 * MessengerMessage::sentByColumnReady() / WhatsAppMessage::
 * sentByColumnReady() for how that distinction is made.
 *
 * Deliberately application-level, not a fine-tuned model: this only ever
 * feeds a small amount of real text into the existing GPT call as extra
 * prompt context. Deliberately does NOT build a separate "business
 * memory" (recurring FAQ/objection clustering, semantic topic-relevance
 * retrieval, etc.) — at current data volumes a handful of recency-biased
 * real examples already gives GPT everything it needs to infer tone,
 * structure, and typical length, and building a heavier retrieval system
 * on top would be premature complexity for what this actually needs to
 * do. Recency is already a form of "recent behavior matters more": the
 * underlying query is always the tenant's MOST RECENT human replies, so a
 * genuine change in how a business communicates is reflected the next
 * time the cache rebuilds (see forgetMessengerStyleCache()) without any
 * separate "trend detection" logic.
 *
 * Deliberately does NOT touch AiChatService's tone — that's a completely
 * different audience (the tenant's own staff talking to the AI in the
 * panel), with its own separate system prompt.
 */
class AiConversationStyleService
{
    /**
     * A one-line style profile (typical reply length, address-term/emoji
     * habits) followed by a compact block of real "Customer: ... /
     * Reply: ..." pairs — or '' when there isn't enough usable human-reply
     * history yet (a brand new tenant, the sent_by column not imported
     * yet, or the memory lookup itself failing for any reason). An empty
     * string is always a normal, expected case, never an error that
     * should block a reply — AiAgentService simply omits the
     * style-examples section of the prompt when this is empty, falling
     * back to the general style rules alone. See the class docblock for
     * why this must stay a single compact string, never a large context.
     *
     * Cached per tenant (config('ai.style_cache_minutes')) so this DB work
     * happens roughly once per cache window, not on every inbound message.
     * Never throws: a failure here (a transient DB/cache error) degrades
     * to no style examples for this one reply rather than ever blocking
     * or crashing the AI reply pipeline — see ProcessAiAgentMessage, which
     * has no special handling for this and shouldn't need any.
     */
    public function messengerStyleExamples(int $tenantId): string
    {
        if (! MessengerMessage::sentByColumnReady()) {
            return '';
        }

        try {
            return Cache::remember(
                $this->messengerCacheKey($tenantId),
                now()->addMinutes((int) config('ai.style_cache_minutes', 360)),
                fn () => $this->buildFromHistory($tenantId)
            );
        } catch (\Throwable $e) {
            Log::warning('AI conversation style: failed to build or read the style profile — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return '';
        }
    }

    /**
     * Busts this tenant's cached style profile so the very next AI reply
     * picks up fresh history immediately, instead of waiting up to
     * config('ai.style_cache_minutes'). Called whenever a genuine human
     * reply is recorded (see Tenant\MessengerInboxController::reply()) —
     * a human correction ("না আপু, দাম আসলে ১৩৫০ টাকা") is exactly the
     * kind of high-value signal that should influence the AI's tone
     * without a several-hour delay. Only ever busts THIS tenant's own key
     * — never a cross-tenant operation.
     */
    public function forgetMessengerStyleCache(int $tenantId): void
    {
        Cache::forget($this->messengerCacheKey($tenantId));
    }

    protected function messengerCacheKey(int $tenantId): string
    {
        return "ai_style_examples:messenger:{$tenantId}";
    }

    /**
     * Phase 7 — WhatsApp counterpart of messengerStyleExamples() above.
     * Structurally mirrored, not shared, the same "independent
     * implementation per channel" convention App\Jobs\
     * ProcessWhatsAppAiAgentMessage's own docblock already establishes for
     * this codebase — only ever learns from whatsapp_messages rows with
     * sent_by='human', gated by WhatsAppMessage::sentByColumnReady() the
     * same way this class gates on MessengerMessage::sentByColumnReady().
     * Cached and degrades to '' on failure for the same reasons.
     */
    public function whatsappStyleExamples(int $tenantId): string
    {
        if (! WhatsAppMessage::sentByColumnReady()) {
            return '';
        }

        try {
            return Cache::remember(
                $this->whatsappCacheKey($tenantId),
                now()->addMinutes((int) config('ai.style_cache_minutes', 360)),
                fn () => $this->buildFromWhatsAppHistory($tenantId)
            );
        } catch (\Throwable $e) {
            Log::warning('AI conversation style: failed to build or read the WhatsApp style profile — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return '';
        }
    }

    /**
     * WhatsApp counterpart of forgetMessengerStyleCache() — called from
     * Tenant\WhatsAppInboxController::reply() after a genuine human reply.
     */
    public function forgetWhatsAppStyleCache(int $tenantId): void
    {
        Cache::forget($this->whatsappCacheKey($tenantId));
    }

    protected function whatsappCacheKey(int $tenantId): string
    {
        return "ai_style_examples:whatsapp:{$tenantId}";
    }

    protected function buildFromWhatsAppHistory(int $tenantId): string
    {
        $max = max(0, (int) config('ai.style_examples_max', 6));

        if ($max === 0) {
            return '';
        }

        $humanReplies = WhatsAppMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'out')
            ->where('sent_by', 'human')
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($max * 3)
            ->get(['id', 'wa_id', 'message_text']);

        if ($humanReplies->isEmpty()) {
            return '';
        }

        $precedingByWaId = $this->batchPrecedingInboundWhatsApp($tenantId, $humanReplies);

        $pairs = [];

        foreach ($humanReplies as $reply) {
            if (count($pairs) >= $max) {
                break;
            }

            $customerMessage = ($precedingByWaId->get($reply->wa_id) ?? collect())
                ->first(fn ($m) => $m->id < $reply->id)?->message_text;

            if (! $customerMessage) {
                continue;
            }

            $pairs[] = [
                $this->trimExample($customerMessage),
                $this->trimExample($reply->message_text),
            ];
        }

        if (! $pairs) {
            return '';
        }

        $pairs = array_reverse($pairs);

        $examplesBlock = implode("\n\n", array_map(
            fn (array $pair) => "Customer: {$pair[0]}\nReply: {$pair[1]}",
            $pairs
        ));

        $profileLine = $this->buildProfileLine($humanReplies->pluck('message_text'));

        return $profileLine === ''
            ? $examplesBlock
            : $profileLine."\n\n".$examplesBlock;
    }

    protected function buildFromHistory(int $tenantId): string
    {
        $max = max(0, (int) config('ai.style_examples_max', 6));

        if ($max === 0) {
            return '';
        }

        // Over-fetch a bit: not every human reply has a matching preceding
        // customer message to pair with (e.g. a staff-initiated message),
        // so some candidates get skipped below. Most-recent-first is the
        // whole "recency matters more than old history" mechanism — see
        // the class docblock.
        $humanReplies = MessengerMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'out')
            ->where('sent_by', 'human')
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($max * 3)
            ->get(['id', 'sender_psid', 'message_text']);

        if ($humanReplies->isEmpty()) {
            return '';
        }

        $precedingByPsid = $this->batchPrecedingInboundMessenger($tenantId, $humanReplies);

        $pairs = [];

        foreach ($humanReplies as $reply) {
            if (count($pairs) >= $max) {
                break;
            }

            $customerMessage = ($precedingByPsid->get($reply->sender_psid) ?? collect())
                ->first(fn ($m) => $m->id < $reply->id)?->message_text;

            if (! $customerMessage) {
                continue;
            }

            $pairs[] = [
                $this->trimExample($customerMessage),
                $this->trimExample($reply->message_text),
            ];
        }

        if (! $pairs) {
            return '';
        }

        // Oldest-first reads more naturally as a set of examples than the
        // most-recent-first order they were fetched in.
        $pairs = array_reverse($pairs);

        $examplesBlock = implode("\n\n", array_map(
            fn (array $pair) => "Customer: {$pair[0]}\nReply: {$pair[1]}",
            $pairs
        ));

        $profileLine = $this->buildProfileLine($humanReplies->pluck('message_text'));

        return $profileLine === ''
            ? $examplesBlock
            : $profileLine."\n\n".$examplesBlock;
    }

    /**
     * Phase 17 — one batched query for every candidate reply's preceding
     * inbound message, replacing what used to be one extra query PER
     * candidate reply (up to style_examples_max * 3 of them). Fetches the
     * most recent inbound messages across every psid involved, in one
     * round trip, then buildFromHistory() pairs each reply with its own
     * preceding message in memory (still applying the exact same "most
     * recent message with id < this reply's id" rule per reply — the
     * batching only changes how many queries that takes, never the
     * result). The extra ' * 5' headroom on the row limit is a safety
     * margin, not a per-psid guarantee — if a customer genuinely sent
     * more than ~5 consecutive messages between two staff replies, that
     * one candidate simply gets skipped, same as today's existing
     * "no preceding message found" fallback already handles.
     *
     * @param  Collection<int, MessengerMessage>  $humanReplies
     * @return Collection<string, Collection<int, MessengerMessage>> keyed by sender_psid
     */
    protected function batchPrecedingInboundMessenger(int $tenantId, Collection $humanReplies): Collection
    {
        $psids = $humanReplies->pluck('sender_psid')->unique()->values();

        return MessengerMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('sender_psid', $psids)
            ->where('direction', 'in')
            ->where('id', '<', $humanReplies->max('id'))
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($humanReplies->count() * 5)
            ->get(['id', 'sender_psid', 'message_text'])
            ->groupBy('sender_psid');
    }

    /** WhatsApp counterpart of batchPrecedingInboundMessenger() — see its docblock. */
    protected function batchPrecedingInboundWhatsApp(int $tenantId, Collection $humanReplies): Collection
    {
        $waIds = $humanReplies->pluck('wa_id')->unique()->values();

        return WhatsAppMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('wa_id', $waIds)
            ->where('direction', 'in')
            ->where('id', '<', $humanReplies->max('id'))
            ->whereNotNull('message_text')
            ->orderByDesc('id')
            ->limit($humanReplies->count() * 5)
            ->get(['id', 'wa_id', 'message_text'])
            ->groupBy('wa_id');
    }

    /**
     * A cheap, explainable summary computed from the same small sample
     * already fetched above — no extra query, no extra API call. Gives
     * the model a concrete length/habit target instead of only raw
     * examples, directly addressing "match the tenant's real average
     * reply length" rather than an artificial fixed short/long rule.
     *
     * @param  Collection<int, string>  $replyTexts  Untrimmed reply texts
     *                                               (deliberately computed before trimExample() shortens them for
     *                                               display, so the length signal reflects real historical length).
     */
    protected function buildProfileLine(Collection $replyTexts): string
    {
        if ($replyTexts->isEmpty()) {
            return '';
        }

        $avgSentences = round($replyTexts->map(fn (string $t) => $this->countSentences($t))->avg(), 1);
        $avgChars = (int) round($replyTexts->map(fn (string $t) => mb_strlen($t))->avg());

        $emojiRate = $replyTexts->filter(fn (string $t) => $this->containsEmoji($t))->count() / $replyTexts->count();
        $addressTermRate = $replyTexts->filter(fn (string $t) => preg_match('/আপু|ভাইয়া/u', $t) === 1)->count() / $replyTexts->count();

        $emojiHabit = match (true) {
            $emojiRate >= 0.5 => 'often uses emojis',
            $emojiRate >= 0.15 => 'occasionally uses emojis',
            default => 'rarely uses emojis',
        };

        $addressHabit = match (true) {
            $addressTermRate >= 0.5 => 'often uses আপু/ভাইয়া',
            $addressTermRate >= 0.15 => 'sometimes uses আপু/ভাইয়া, but not in most messages',
            default => 'rarely uses আপু/ভাইয়া as a fixed address term',
        };

        return "This business's staff typically reply in about {$avgSentences} sentence(s) (~{$avgChars} characters), {$addressHabit}, and {$emojiHabit} — match this length and habit, not a fixed rule.";
    }

    protected function countSentences(string $text): int
    {
        $count = preg_match_all('/[.!?।]+/u', $text);

        return max(1, (int) $count);
    }

    protected function containsEmoji(string $text): bool
    {
        return preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text) === 1;
    }

    /**
     * Keeps one unusually long historical message (a one-off detailed
     * explanation, say) from blowing up the compact example block —
     * truncates on a word boundary rather than mid-word.
     */
    protected function trimExample(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $limit = max(20, (int) config('ai.style_example_max_chars', 150));

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return $truncated.'…';
    }
}
