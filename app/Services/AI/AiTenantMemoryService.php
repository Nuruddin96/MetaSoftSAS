<?php

namespace App\Services\AI;

use App\Models\AiTenantMemory;
use Illuminate\Support\Facades\Log;

/**
 * "AI মেমোরী" — the RELEVANT SAVED Q&A layer. Every Q&A a tenant saves
 * from Settings (Tenant\AiMemoryController) is stored verbatim in
 * tenant_ai_memories at zero AI cost — no embeddings, no OpenAI call to
 * store it, per the task's explicit constraint. Matching a real customer
 * message against those saved questions at REPLY time is a single cheap,
 * bounded, deterministic keyword-overlap score — same "prefer simple
 * reliable architecture first" choice as AiProductKnowledgeService::
 * relevantProducts()'s docblock, never a vector/embedding search and
 * never a second paid AI call per message.
 *
 * Only the top few best-matching memories (config('ai.memory_match_max'))
 * above a minimum overlap ratio (config('ai.memory_match_min_ratio')) are
 * ever returned — every tenant memory is never dumped into every prompt,
 * so a tenant with a long saved Q&A list doesn't silently inflate every
 * customer message's token cost.
 *
 * A memory whose answer is a recorded/uploaded voice clip (answer_type=
 * 'audio', database/sql/chunk42.sql) is excluded from relevantMemories()'s
 * text prompt block — there is no text to quote into the prompt — and is
 * instead reachable only via bestAudioMatch(), which the AI job checks
 * BEFORE ever calling OpenAI (see that method's docblock).
 */
class AiTenantMemoryService
{
    /**
     * Common function words in English/Banglish/Bengali, stripped before
     * scoring so two questions overlapping only on "what/is/the/কি/কত"
     * don't score as a match — these carry no topic signal on their own.
     */
    protected const STOPWORDS = [
        'what', 'is', 'are', 'the', 'a', 'an', 'do', 'does', 'how', 'much', 'many',
        'for', 'to', 'in', 'on', 'at', 'of', 'and', 'or', 'please', 'you', 'your',
        'i', 'my', 'me', 'can', 'will', 'be', 'it', 'this', 'that',
        'কি', 'কী', 'কত', 'কেমন', 'আছে', 'করে', 'করেন', 'হয়', 'হবে', 'কোথায়',
        'এটা', 'ওটা', 'সেটা', 'আমার', 'আমি', 'আপনার', 'আপনি', 'দয়া', 'করুন',
    ];

    /**
     * @param  array<int, string>  $conversationTexts  Recent conversation text —
     *                                                 the current customer message plus recent history — same shape
     *                                                 AiProductKnowledgeService::relevantProducts() takes.
     * @return string 'Q: ...\nA: ...' blocks for the best-matching TEXT-answer saved
     *                memories, blank-line separated, most relevant first; '' when
     *                nothing matches (or no memories exist, or the table isn't
     *                imported yet) — same "empty is a normal case, not an error"
     *                contract every other AI knowledge service in this codebase
     *                follows.
     */
    public function relevantMemories(int $tenantId, array $conversationTexts): string
    {
        $scored = $this->scoredMemories($tenantId, $conversationTexts);
        $max = max(0, (int) config('ai.memory_match_max', 3));

        $lines = [];

        foreach ($scored as $row) {
            if ($row['memory']->isAudioAnswer()) {
                continue;
            }

            $lines[] = "Q: {$row['memory']->question}\nA: {$row['memory']->answer}";

            if (count($lines) >= $max) {
                break;
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * The AI job (ProcessAiAgentMessage/ProcessWhatsAppAiAgentMessage)
     * calls this BEFORE building any prompt or calling OpenAI. A strong
     * match here means the tenant recorded/uploaded an exact voice answer
     * for essentially this question — sending that file directly via the
     * channel's existing outbound-media path (MessengerApi::
     * sendAttachment()/WhatsAppSendService::sendMedia()) is both more
     * faithful to what the tenant actually recorded and, per the task's
     * explicit requirement, avoids "unnecessarily generating a new
     * answer" (zero OpenAI cost, zero AI credit deducted).
     *
     * Uses a stricter ratio than relevantMemories() (config
     * 'ai.memory_audio_match_min_ratio', default higher than the general
     * text-match threshold) — short-circuiting the AI entirely and
     * sending a specific pre-recorded clip is a more consequential action
     * than merely mentioning a fact in a generated reply, so it should
     * only fire on a genuinely confident match, never a loose partial one.
     */
    public function bestAudioMatch(int $tenantId, array $conversationTexts): ?AiTenantMemory
    {
        $minRatio = (float) config('ai.memory_audio_match_min_ratio', 0.6);

        foreach ($this->scoredMemories($tenantId, $conversationTexts) as $row) {
            if ($row['memory']->isAudioAnswer() && $row['ratio'] >= $minRatio) {
                return $row['memory'];
            }
        }

        return null;
    }

    /** @return array<int, array{ratio: float, overlap: int, memory: AiTenantMemory}> Sorted best-first. */
    protected function scoredMemories(int $tenantId, array $conversationTexts): array
    {
        $haystackTokens = $this->tokens(implode(' ', array_filter($conversationTexts)));

        if ($haystackTokens === []) {
            return [];
        }

        try {
            if (! AiTenantMemory::tablesReady()) {
                return [];
            }

            $columns = AiTenantMemory::voiceColumnsReady()
                ? ['id', 'question', 'answer', 'answer_type', 'answer_audio_path']
                : ['id', 'question', 'answer'];

            $memories = AiTenantMemory::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit((int) config('ai.memory_match_scan_limit', 200))
                ->get($columns);
        } catch (\Throwable $e) {
            Log::warning('AI tenant memory: failed to scan saved Q&A — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return [];
        }

        $minRatio = (float) config('ai.memory_match_min_ratio', 0.4);
        $scored = [];

        foreach ($memories as $memory) {
            $questionTokens = $this->tokens($memory->question);

            if ($questionTokens === []) {
                continue;
            }

            $overlap = count(array_intersect($questionTokens, $haystackTokens));

            if ($overlap === 0) {
                continue;
            }

            $ratio = $overlap / count($questionTokens);

            if ($ratio < $minRatio) {
                continue;
            }

            $scored[] = ['ratio' => $ratio, 'overlap' => $overlap, 'memory' => $memory];
        }

        usort($scored, fn ($a, $b) => $b['ratio'] <=> $a['ratio'] ?: $b['overlap'] <=> $a['overlap']);

        return $scored;
    }

    /** @return array<int, string> */
    protected function tokens(string $text): array
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn ($word) => mb_strlen($word) >= 2 && ! in_array($word, self::STOPWORDS, true)
        )));
    }
}
