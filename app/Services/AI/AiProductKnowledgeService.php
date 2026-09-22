<?php

namespace App\Services\AI;

use App\Models\Product;
use App\Services\AI\Tools\AiToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 — "RELEVANT PRODUCT DATA" in the context pipeline. Resolves
 * which of the tenant's own real products were mentioned anywhere in the
 * conversation, then pulls their REAL, current price/stock/variant data
 * via the existing lookup_products tool (App\Services\AI\Tools\
 * ProductLookupTool) — never a second, duplicate product-fetching query
 * path. This directly closes the production example that motivated this
 * whole phase: "COSRX Snail Cream টার দাম কত?" previously got "কোন
 * প্রোডাক্টের কথা বলছেন?" because the AI had no product data access at
 * all; it can now answer with the real price.
 *
 * Deliberately NOT a full AI tool-calling loop on the public Messenger/
 * WhatsApp flow (AiChatService already has one, for the authenticated
 * panel only) — see AiToolRegistry::call()'s own docblock, which
 * explicitly anticipates exactly this "deterministic Laravel code calls a
 * tool directly, zero extra AI cost" pattern.
 *
 * Matching is two cheap, bounded, deterministic passes over the tenant's
 * own product names — never an extra paid AI call, never a vector/
 * embedding search:
 *
 *  1. Exact (substring) pass, scanned from the MOST RECENT conversation
 *     turn backwards — a product name that literally appears anywhere in
 *     the conversation (customer OR the AI's own earlier replies) is a
 *     confident match, and the one found in the most recent turn becomes
 *     "the currently discussed product" (see resolve()'s docblock). This
 *     is what makes a vague follow-up like "eta koto?" resolve correctly:
 *     the product itself doesn't need to be named again in THIS message,
 *     only somewhere recent enough to still be in context_messages.
 *  2. Fuzzy (token-overlap) pass, scanned ONLY against the customer's
 *     current message — catches a typo'd/partial/reordered product name
 *     ("briliant set" for "Brilliant Skin Rejuvenating Set") that the
 *     exact pass would miss. Same overlap-ratio technique
 *     AiTenantMemoryService already uses for saved Q&A matching, just
 *     applied to product names instead of saved questions — deliberately
 *     not extracted into shared code (see ProcessAiAgentMessage::
 *     resolveOutboundToken()'s docblock for why this codebase prefers a
 *     small duplicated concern over a shared abstraction here).
 *
 * Confidence rule: if the fuzzy pass alone finds exactly one candidate,
 * that's a confident match (resolved automatically). If it finds two or
 * more for the SAME current message with no exact/recent match already
 * resolving it, that's genuine ambiguity — those names are surfaced
 * separately as "ambiguous", never silently guessed, so the prompt can
 * tell the model to ask instead of picking the closest one.
 *
 * CRITICAL: ProductLookupTool's raw result includes purchase_price (the
 * tenant's wholesale cost) — correct for the authenticated staff-facing
 * panel chat, but this must NEVER reach a customer-facing Messenger/
 * WhatsApp reply. formatProduct() below is an explicit allow-list of only
 * customer-safe fields (name, variant, selling price, stock) — never a
 * blind pass-through of the tool's full output.
 */
class AiProductKnowledgeService
{
    /** Mirrors AiTenantMemoryService::STOPWORDS — same reasoning, kept independent since these are separate matching concerns over different data. */
    protected const STOPWORDS = [
        'what', 'is', 'are', 'the', 'a', 'an', 'do', 'does', 'how', 'much', 'many',
        'for', 'to', 'in', 'on', 'at', 'of', 'and', 'or', 'please', 'you', 'your',
        'i', 'my', 'me', 'can', 'will', 'be', 'it', 'this', 'that', 'ta', 'ti', 'eta', 'oita',
        'কি', 'কী', 'কত', 'কেমন', 'আছে', 'করে', 'করেন', 'হয়', 'হবে', 'কোথায়',
        'এটা', 'ওটা', 'সেটা', 'আমার', 'আমি', 'আপনার', 'আপনি', 'দয়া', 'করুন',
    ];

    public function __construct(protected AiToolRegistry $tools) {}

    /**
     * @param  array<int, string>  $conversationTexts  Recent conversation text,
     *                                                 OLDEST FIRST — the same shape callers already build
     *                                                 ([...history, currentMessage]) — searched for a literal or
     *                                                 fuzzy mention of one of the tenant's own product names.
     */
    public function relevantProducts(int $tenantId, array $conversationTexts): string
    {
        $resolved = $this->resolve($tenantId, $conversationTexts);

        if (! $resolved) {
            return '';
        }

        $max = max(0, (int) config('ai.product_match_max', 3));
        $lines = [];

        foreach ($resolved['matches'] as $i => $match) {
            if (count($lines) >= $max) {
                break;
            }

            $result = $this->tools->call('lookup_products', $tenantId, ['name' => $match['name'], 'limit' => 1]);

            if (! $result->successful) {
                continue;
            }

            foreach ($result->data['products'] ?? [] as $product) {
                // Only the single most-recently-mentioned match is labeled
                // "currently discussed" — this is the one a vague "eta"/
                // "this one" should resolve to; the rest are other
                // products also named somewhere recent, given for
                // reference but not assumed to be what a pronoun means.
                $prefix = $i === 0 ? '[Product currently being discussed] ' : '';
                $lines[] = $prefix.$this->formatProduct($product);
            }
        }

        $out = implode("\n", $lines);

        if ($resolved['ambiguous']) {
            $names = implode(', ', $resolved['ambiguous']);
            $note = "[AMBIGUOUS REFERENCE: the customer's message could match more than one product with similar confidence ({$names}), and nothing in the conversation clearly picks one. Do NOT guess or answer about either — ask a short clarifying question naming the candidates instead.]";
            $out = $out ? "{$out}\n\n{$note}" : $note;
        }

        return $out;
    }

    /**
     * The single product most recently, clearly discussed in this
     * conversation — used by the image-request short-circuit (see
     * App\Jobs\ProcessAiAgentMessage::maybeSendProductImage()) to resolve
     * "pic den"/"ছবি দেন" against the right product without a second AI
     * call. Deliberately returns null (never a guess) when the resolve()
     * pass found genuine ambiguity for the current message, or found
     * nothing at all — a missing/ambiguous photo request falls through to
     * the normal AI reply, which already knows (via the system prompt) to
     * say so honestly or ask rather than pretend a photo was sent.
     *
     * @return array{id: int, name: string, image_url: ?string}|null
     */
    public function currentProduct(int $tenantId, array $conversationTexts): ?array
    {
        $resolved = $this->resolve($tenantId, $conversationTexts);

        if (! $resolved || $resolved['ambiguous'] || ! $resolved['matches']) {
            return null;
        }

        $name = $resolved['matches'][0]['name'];

        try {
            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->where('name', $name)
                ->with('images')
                ->first();
        } catch (\Throwable $e) {
            Log::warning('AI product knowledge: failed to load current product for image resolution.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return null;
        }

        if (! $product) {
            return null;
        }

        $path = $product->thumbnail_path ?: $product->images->sortBy('sort_order')->first()?->image_path;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'image_url' => $path ? asset('storage/'.$path) : null,
        ];
    }

    /**
     * Shared resolution pass behind both relevantProducts() and
     * currentProduct() — a single scan of the tenant's product names,
     * scored against the conversation once, so the two callers never run
     * this twice for the same message.
     *
     * @param  array<int, string>  $conversationTexts  Oldest first.
     * @return array{matches: array<int, array{name: string}>, ambiguous: array<int, string>}|null
     *                                                                                            matches is ordered most-recently-discussed first; null when there's
     *                                                                                            nothing to search (no text) or the product scan itself failed.
     */
    protected function resolve(int $tenantId, array $conversationTexts): ?array
    {
        $conversationTexts = array_values(array_filter($conversationTexts, fn ($t) => trim((string) $t) !== ''));

        if ($conversationTexts === []) {
            return null;
        }

        try {
            $productNames = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->limit((int) config('ai.product_match_scan_limit', 200))
                ->pluck('name')
                ->all();
        } catch (\Throwable $e) {
            Log::warning('AI product knowledge: failed to scan product names — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return null;
        }

        if ($productNames === []) {
            return null;
        }

        $max = max(0, (int) config('ai.product_match_max', 3));

        // Pass 1 — exact substring match, most recent turn first. The
        // first product name found this way is "the currently discussed
        // product"; later distinct matches are still included (up to
        // $max) as other recently-relevant products, but never displace
        // the most recent one from the top slot.
        $exactMatches = [];

        foreach (array_reverse($conversationTexts) as $text) {
            if (count($exactMatches) >= $max) {
                break;
            }

            $haystack = mb_strtolower($text);

            foreach ($productNames as $name) {
                if (count($exactMatches) >= $max) {
                    break;
                }

                if (isset($exactMatches[$name])) {
                    continue;
                }

                if (str_contains($haystack, mb_strtolower($name))) {
                    $exactMatches[$name] = true;
                }
            }
        }

        // Pass 2 — fuzzy token-overlap match, current (last) message only.
        // Only products NOT already found by the exact pass are
        // candidates here, since an exact match is always the stronger
        // signal.
        $currentMessage = end($conversationTexts);
        $currentTokens = $this->tokens($currentMessage);
        $fuzzyCandidates = [];

        if ($currentTokens !== []) {
            $minRatio = (float) config('ai.product_fuzzy_match_min_ratio', 0.6);

            foreach ($productNames as $name) {
                if (isset($exactMatches[$name])) {
                    continue;
                }

                $nameTokens = $this->tokens($name);

                if ($nameTokens === []) {
                    continue;
                }

                $overlap = count(array_intersect($nameTokens, $currentTokens));

                if ($overlap === 0) {
                    continue;
                }

                $ratio = $overlap / count($nameTokens);

                if ($ratio >= $minRatio) {
                    $fuzzyCandidates[] = ['name' => $name, 'ratio' => $ratio];
                }
            }
        }

        usort($fuzzyCandidates, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);

        $matches = array_map(fn (string $name) => ['name' => $name], array_keys($exactMatches));
        $ambiguous = [];

        if (count($fuzzyCandidates) === 1) {
            // Exactly one fuzzy candidate, nothing already resolved it
            // exactly — a single strong match is confident enough to
            // resolve automatically (still bounded by $max below).
            $matches[] = ['name' => $fuzzyCandidates[0]['name']];
        } elseif (count($fuzzyCandidates) >= 2 && $exactMatches === []) {
            // Two or more similarly-fuzzy candidates for THIS message and
            // no exact/recent match already picked one — genuine
            // ambiguity, never guessed.
            $ambiguous = array_column($fuzzyCandidates, 'name');
        }

        $matches = array_slice($matches, 0, $max);

        if ($matches === [] && $ambiguous === []) {
            return null;
        }

        return ['matches' => $matches, 'ambiguous' => $ambiguous];
    }

    /**
     * Explicit allow-list of customer-safe fields only — see class
     * docblock's CRITICAL note. purchase_price/sku are deliberately never
     * read here, even though the tool result contains them.
     */
    protected function formatProduct(array $product): string
    {
        $variants = collect($product['variants'] ?? [])->map(function (array $variant) {
            $label = $variant['variant_name'] ? " ({$variant['variant_name']})" : '';
            $stock = ((int) $variant['stock_quantity']) > 0 ? "{$variant['stock_quantity']} in stock" : 'out of stock';

            return "{$label} price {$variant['selling_price']}, {$stock}";
        })->implode(';');

        return "{$product['name']}:{$variants}";
    }

    /** Mirrors AiTenantMemoryService::tokens() — same tokenizer shape, kept independent (see class docblock). */
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
