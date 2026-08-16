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
 * tool directly, zero extra AI cost" pattern. Matching is a single cheap,
 * bounded substring check against the tenant's own product names — not an
 * extra paid AI call, not NLP, not a vector search. This is a deliberate
 * "prefer simple reliable architecture first" choice: variant-only
 * references ("the black one") with no product ever named are left to the
 * model's own conversational reasoning over whatever text context it
 * already has, not a second string-matching layer trying to guess them.
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
    public function __construct(protected AiToolRegistry $tools) {}

    /**
     * @param  array<int, string>  $conversationTexts  Recent conversation text —
     *                                                 the current customer message plus recent history — searched for a
     *                                                 literal mention of one of the tenant's own product names.
     */
    public function relevantProducts(int $tenantId, array $conversationTexts): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter($conversationTexts)));

        if (trim($haystack) === '') {
            return '';
        }

        try {
            $productNames = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->limit((int) config('ai.product_match_scan_limit', 200))
                ->pluck('name', 'id');
        } catch (\Throwable $e) {
            Log::warning('AI product knowledge: failed to scan product names — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return '';
        }

        $max = max(0, (int) config('ai.product_match_max', 3));
        $lines = [];

        foreach ($productNames as $name) {
            if (count($lines) >= $max) {
                break;
            }

            if (! str_contains($haystack, mb_strtolower($name))) {
                continue;
            }

            $result = $this->tools->call('lookup_products', $tenantId, ['name' => $name, 'limit' => 1]);

            if (! $result->successful) {
                continue;
            }

            foreach ($result->data['products'] ?? [] as $product) {
                $lines[] = $this->formatProduct($product);
            }
        }

        return implode("\n", $lines);
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
}
