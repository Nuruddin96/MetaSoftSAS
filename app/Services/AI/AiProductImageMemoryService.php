<?php

namespace App\Services\AI;

use App\Models\TenantProductImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * "পণ্যের ছবি" (Product Image Memory) — resolves a customer's image
 * request ("ছবি দেন" / "pic den" / "cosrx snail এর ছবি দেন") against the
 * tenant's own saved product-name -> image rows (tenant_product_images,
 * database/sql/chunk50.sql), entirely deterministically and at zero AI
 * cost — same "cheap, bounded, no embeddings, no second OpenAI call"
 * design AiTenantMemoryService::bestAudioMatch() already established for
 * saved voice answers (see that method's docblock). The calling job
 * checks this BEFORE ever building a prompt or calling OpenAI.
 *
 * Deliberately category-agnostic: nothing here assumes what "a product"
 * is beyond whatever free-text name the tenant typed when saving the
 * image — a skincare set, a piece of clothing, an electronics SKU, and a
 * service package all resolve through the exact same matching logic.
 *
 * Resolution has two stages:
 *
 *  1. Explicit product name in the CURRENT message ("cosrx snail cream
 *     এর ছবি দেন") — a direct keyword-overlap score (same tokenizer/
 *     stopword approach as AiTenantMemoryService) against every saved
 *     image's product_name, picking a confident, unambiguous winner.
 *
 *  2. No product named ("ছবি দেন" alone) — a conversation-relevance
 *     score across recent history: every saved image accumulates points
 *     from every history line that mentions its product, weighted by
 *     recency (later turns count more) and boosted when that line also
 *     asked a price/stock/usage/availability/order-shaped question about
 *     it. This approximates "which product has actually been the live
 *     topic of conversation," not just "was it named once" — a product
 *     asked about repeatedly and in depth outscores one mentioned only
 *     in passing, and a product raised most recently outweighs one from
 *     several turns ago.
 *
 * Two or more comparably-scored candidates deliberately resolve to
 * "ambiguous" rather than picking one — see AiImageRequestResolution's
 * docblock. A message with no recognizable image-request phrasing at all
 * never touches the database (imageRequested() short-circuits first).
 */
class AiProductImageMemoryService
{
    /**
     * Words that identify "a picture" itself — Bengali, Banglish, and
     * English. Matched as whole tokens (see rawTokens()), never a bare
     * substring, so a product genuinely named with one of these words
     * doesn't matter for false-positive purposes here (this list only
     * drives request DETECTION; see STOPWORDS below for why a product
     * literally named e.g. "Photo Frame" is a known, accepted edge case).
     */
    protected const IMAGE_NOUNS = [
        'ছবি', 'ছবিটা', 'ছবিটার', 'ফটো', 'ফটোটা', 'photo', 'photos',
        'pic', 'pics', 'picture', 'pictures', 'image', 'images',
        'chobi', 'chhobi', 'chobita', 'chobir',
    ];

    /**
     * Request-shaped verbs/particles that, alongside an IMAGE_NOUNS
     * token, make the message an actual request rather than an unrelated
     * mention (e.g. "আমি ছবি দেখেছিলাম কিন্তু বুঝি নাই" has the noun but
     * none of these, so it correctly does NOT trigger). A bare short
     * message (<=3 tokens) is still treated as a request even without one
     * of these — real customers very often just send "pic?" or "ছবি"
     * alone — see imageRequested()'s own logic.
     */
    protected const REQUEST_VERBS = [
        'দেন', 'দাও', 'দিন', 'দিবেন', 'দিতে', 'চাই', 'লাগবে',
        'পাঠান', 'পাঠাও', 'দেখান', 'দেখাও', 'দেখতে',
        'den', 'dao', 'dan', 'dben', 'dibe', 'chai', 'lagbe',
        'dekhan', 'dekhao', 'pathao', 'pathan',
        'send', 'please', 'plz', 'pls', 'share', 'koi', 'kothay', 'dorkar',
    ];

    /**
     * Common function words (English/Banglish/Bengali) stripped before
     * scoring a PRODUCT match, so two names overlapping only on filler
     * words never score as a match — mirrors AiTenantMemoryService::
     * STOPWORDS. tokens() below additionally strips IMAGE_NOUNS/
     * REQUEST_VERBS on top of this list (the request phrasing itself must
     * never count as "product name overlap").
     *
     * Known, accepted trade-off: a product genuinely named with one of
     * these words (e.g. a real product called "Picture Frame") loses that
     * word from its own matchable tokens too. Same class of trade-off
     * AiTenantMemoryService::STOPWORDS already accepts for common
     * question words.
     */
    protected const STOPWORDS = [
        'what', 'is', 'are', 'the', 'a', 'an', 'do', 'does', 'how', 'much', 'many',
        'for', 'to', 'in', 'on', 'at', 'of', 'and', 'or', 'please', 'you', 'your',
        'i', 'my', 'me', 'can', 'will', 'be', 'it', 'this', 'that', 'one',
        'কি', 'কী', 'কত', 'কেমন', 'আছে', 'করে', 'করেন', 'হয়', 'হবে', 'কোথায়',
        'এটা', 'ওটা', 'সেটা', 'আমার', 'আমি', 'আপনার', 'আপনি', 'দয়া', 'করুন',
        'এর', 'টার', 'টা', 'টি', 'আর', 'ও',
    ];

    /** Question-shape signals that boost a history line's contribution when it co-occurs with a product mention — see class docblock, stage 2. */
    protected const SIGNAL_WORDS = [
        'দাম', 'price', 'কত', 'tk', 'taka', 'টাকা',
        'স্টক', 'stock', 'available', 'stok',
        'সাইজ', 'size', 'variant', 'কালার', 'color', 'colour',
        'ব্যবহার', 'use', 'usage', 'কিভাবে', 'কীভাবে', 'how',
        'অর্ডার', 'order', 'কিনতে', 'buy', 'কেনা',
        'ডেলিভারি', 'delivery',
    ];

    /**
     * @param  array<int, string>  $historyTexts  Recent conversation text, oldest first —
     *                                            same shape AiProductKnowledgeService::relevantProducts()/
     *                                            AiTenantMemoryService::relevantMemories() take. Must NOT include
     *                                            $currentMessage itself (this method appends it internally where
     *                                            relevant, in its correct chronological/most-recent position).
     */
    public function resolve(int $tenantId, string $currentMessage, array $historyTexts): AiImageRequestResolution
    {
        if (! $this->imageRequested($currentMessage)) {
            return AiImageRequestResolution::none();
        }

        if (! TenantProductImage::tablesReady()) {
            return AiImageRequestResolution::none();
        }

        $images = $this->scanImages($tenantId);

        if ($images === null || $images->isEmpty()) {
            return AiImageRequestResolution::none();
        }

        $explicit = $this->bestByCurrentMessage($currentMessage, $images);

        if ($explicit) {
            if ($explicit['ambiguous']) {
                return AiImageRequestResolution::clarify();
            }

            return $this->hasSubstantiveContentBeyond($currentMessage, $explicit['image'])
                ? AiImageRequestResolution::sendAndContinue($explicit['image'])
                : AiImageRequestResolution::sendAndStop($explicit['image']);
        }

        $relevant = $this->bestByConversationRelevance([...$historyTexts, $currentMessage], $images);

        if (! $relevant) {
            return AiImageRequestResolution::none();
        }

        if ($relevant['ambiguous']) {
            return AiImageRequestResolution::clarify();
        }

        return $this->hasSubstantiveContentBeyond($currentMessage, null)
            ? AiImageRequestResolution::sendAndContinue($relevant['image'])
            : AiImageRequestResolution::sendAndStop($relevant['image']);
    }

    /** Deterministic, heuristic phrase/token detection — see IMAGE_NOUNS/REQUEST_VERBS docblocks. */
    public function imageRequested(?string $message): bool
    {
        if (! $message || trim($message) === '') {
            return false;
        }

        $tokens = $this->rawTokens($message);
        $hasNoun = array_intersect($tokens, self::IMAGE_NOUNS) !== [];

        if (! $hasNoun) {
            return false;
        }

        $hasVerb = array_intersect($tokens, self::REQUEST_VERBS) !== [];

        return $hasVerb || count($tokens) <= 3;
    }

    /** @return Collection<int, TenantProductImage>|null null on a scan failure (degrades to "no interception"). */
    protected function scanImages(int $tenantId)
    {
        try {
            return TenantProductImage::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderByDesc('id')
                ->limit((int) config('ai.product_image_match_scan_limit', 200))
                ->get(['id', 'product_name', 'image_path']);
        } catch (\Throwable $e) {
            Log::warning('AI product image memory: failed to scan saved product images — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Stage 1 — see class docblock. Returns null when the current message
     * names no recognizable product at all (falls through to stage 2),
     * or an array with the winning image and whether it was a confident,
     * unambiguous winner.
     *
     * @param  Collection<int, TenantProductImage>  $images
     * @return array{image: TenantProductImage, ambiguous: bool}|null
     */
    protected function bestByCurrentMessage(string $message, $images): ?array
    {
        $messageTokens = $this->tokens($message);

        if ($messageTokens === []) {
            return null;
        }

        $minRatio = (float) config('ai.product_image_match_min_ratio', 0.5);
        $scored = [];

        foreach ($images as $image) {
            $nameTokens = $this->tokens($image->product_name);

            if ($nameTokens === []) {
                continue;
            }

            $overlap = count(array_intersect($nameTokens, $messageTokens));

            if ($overlap === 0) {
                continue;
            }

            $ratio = $overlap / count($nameTokens);

            if ($ratio < $minRatio) {
                continue;
            }

            $scored[] = ['score' => $ratio, 'image' => $image];
        }

        if ($scored === []) {
            return null;
        }

        return $this->pickWinner($scored);
    }

    /**
     * Stage 2 — see class docblock. $texts is oldest-first, current
     * message last; recency weight increases linearly with position so
     * the most recent turn counts most, without a magic decay constant.
     *
     * @param  array<int, string>  $texts
     * @param  Collection<int, TenantProductImage>  $images
     * @return array{image: TenantProductImage, ambiguous: bool}|null
     */
    protected function bestByConversationRelevance(array $texts, $images): ?array
    {
        $texts = array_values(array_filter($texts, fn ($t) => trim((string) $t) !== ''));

        if ($texts === []) {
            return null;
        }

        $minRatio = (float) config('ai.product_image_relevance_min_ratio', 0.5);
        $signalBonus = (float) config('ai.product_image_relevance_signal_bonus', 1.5);
        $count = count($texts);
        $scores = [];

        foreach ($images as $image) {
            $nameTokens = $this->tokens($image->product_name);

            if ($nameTokens === []) {
                continue;
            }

            $total = 0.0;

            foreach ($texts as $i => $text) {
                $lineTokens = $this->tokens($text);

                if ($lineTokens === []) {
                    continue;
                }

                $overlap = count(array_intersect($nameTokens, $lineTokens));

                if ($overlap === 0) {
                    continue;
                }

                $ratio = $overlap / count($nameTokens);

                if ($ratio < $minRatio) {
                    continue;
                }

                // Recency weight: oldest line = 1, most recent = $count.
                $weight = $i + 1;
                $contribution = $ratio * $weight;

                if (array_intersect($this->rawTokens($text), self::SIGNAL_WORDS) !== []) {
                    $contribution *= $signalBonus;
                }

                $total += $contribution;
            }

            if ($total > 0.0) {
                $scores[$image->id] = ['score' => $total, 'image' => $image];
            }
        }

        if ($scores === []) {
            return null;
        }

        return $this->pickWinner(array_values($scores));
    }

    /**
     * @param  array<int, array{score: float, image: TenantProductImage}>  $scored
     * @return array{image: TenantProductImage, ambiguous: bool}
     */
    protected function pickWinner(array $scored): array
    {
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $top = $scored[0];

        if (count($scored) === 1) {
            return ['image' => $top['image'], 'ambiguous' => false];
        }

        $runnerUp = $scored[1];
        $margin = (float) config('ai.product_image_confidence_margin', 0.7);

        // Runner-up within $margin of the leader's score is too close to
        // call — e.g. margin=0.7 means a runner-up scoring more than 70%
        // of the leader's score blocks a confident pick. Never guess; see
        // AiImageRequestResolution's clarify() docblock.
        $ambiguous = $runnerUp['score'] > $top['score'] * $margin;

        return ['image' => $top['image'], 'ambiguous' => $ambiguous];
    }

    /**
     * True when the message asks for something beyond just the picture
     * itself (e.g. "দাম কত আর ছবি দেন") — decides send_and_stop vs
     * send_and_continue, see AiImageRequestResolution's docblock.
     */
    protected function hasSubstantiveContentBeyond(string $message, ?TenantProductImage $matchedImage): bool
    {
        $leftover = $this->tokens($message);

        if ($matchedImage) {
            $leftover = array_diff($leftover, $this->tokens($matchedImage->product_name));
        }

        return $leftover !== [];
    }

    /**
     * Lowercased, word-boundary tokens with no stopword filtering — used
     * for request DETECTION, where the request words themselves must
     * survive. The character class deliberately includes \p{M} (Unicode
     * combining marks) alongside \p{L}\p{N} — Bengali vowel signs
     * (matra, e.g. ি/ে) and the virama (্) are combining marks, not
     * letters, on their own Unicode codepoint; a class of only
     * \p{L}\p{N} (the pattern AiTenantMemoryService::tokens() also uses)
     * silently splits composed Bengali words apart at every vowel sign
     * (e.g. "ছবি" -> "ছব" + a dropped "ি"), which breaks exact-token
     * matching against IMAGE_NOUNS/REQUEST_VERBS below even though it
     * happens not to break AiTenantMemoryService's own overlap-only
     * scoring (both sides of that comparison get corrupted the same
     * way). Verified against a live PHP run before fixing — see this
     * class's test suite's imageRequested() coverage.
     */
    protected function rawTokens(string $text): array
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $text, $matches);

        return array_values(array_filter(
            $matches[0],
            fn ($word) => mb_strlen($word) >= 2
        ));
    }

    /** rawTokens() minus STOPWORDS (including the image-request words themselves) — used for PRODUCT-NAME matching/scoring. */
    protected function tokens(string $text): array
    {
        return array_values(array_unique(array_filter(
            $this->rawTokens($text),
            fn ($word) => ! in_array($word, self::STOPWORDS, true)
                && ! in_array($word, self::IMAGE_NOUNS, true)
                && ! in_array($word, self::REQUEST_VERBS, true)
        )));
    }
}
