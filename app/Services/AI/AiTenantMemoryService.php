<?php

namespace App\Services\AI;

use App\Models\AiTenantMemory;
use Illuminate\Support\Facades\Log;

/**
 * "Teach Your AI Agent" — the RELEVANT SAVED Q&A layer. Every Q&A a
 * tenant saves from Settings (Tenant\AiMemoryController) is stored
 * verbatim in tenant_ai_memories at zero AI cost — no embeddings, no
 * OpenAI call to store it, per the task's explicit constraint. Matching a
 * real customer message against those saved questions at REPLY time is a
 * single cheap, bounded, deterministic keyword-overlap score — same
 * "prefer simple reliable architecture first" choice as
 * AiProductKnowledgeService::relevantProducts()'s docblock, never a
 * vector/embedding search and never a second paid AI call per message.
 *
 * Only the top few best-matching memories (config('ai.memory_match_max'))
 * above a minimum overlap ratio (config('ai.memory_match_min_ratio')) are
 * ever returned — every tenant memory is never dumped into every prompt,
 * so a tenant with a long saved Q&A list doesn't silently inflate every
 * customer message's token cost.
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
     * @return string 'Q: ...\nA: ...' blocks for the best-matching saved memories,
     *                blank-line separated, most relevant first; '' when nothing matches
     *                (or no memories exist, or the table isn't imported yet) — same
     *                "empty is a normal case, not an error" contract every other AI
     *                knowledge service in this codebase follows.
     */
    public function relevantMemories(int $tenantId, array $conversationTexts): string
    {
        $haystackTokens = $this->tokens(implode(' ', array_filter($conversationTexts)));

        if ($haystackTokens === []) {
            return '';
        }

        try {
            if (! AiTenantMemory::tablesReady()) {
                return '';
            }

            $memories = AiTenantMemory::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit((int) config('ai.memory_match_scan_limit', 200))
                ->get(['question', 'answer']);
        } catch (\Throwable $e) {
            Log::warning('AI tenant memory: failed to scan saved Q&A — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return '';
        }

        $minRatio = (float) config('ai.memory_match_min_ratio', 0.4);
        $max = max(0, (int) config('ai.memory_match_max', 3));
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

        $lines = [];

        foreach (array_slice($scored, 0, $max) as $row) {
            $lines[] = "Q: {$row['memory']->question}\nA: {$row['memory']->answer}";
        }

        return implode("\n\n", $lines);
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
