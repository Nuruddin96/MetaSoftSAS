<?php

namespace App\Services\AI;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Generic, category-agnostic "is this a post-purchase concern, and can we
 * verify what was actually bought" layer — built for the customer-care
 * side of the AI Customer Sales + Care Agent upgrade. Deliberately makes
 * no assumption about product category: the same phrase list and the
 * same order_items lookup work whether the business sells skincare,
 * clothing, electronics, food, or anything else — nothing here is
 * skincare-specific.
 *
 * Two independent, deterministic responsibilities (never an OpenAI call,
 * same "cheap, bounded, no second AI call" design every other AI service
 * in this codebase follows):
 *
 *  1. isPostPurchaseConcern() — a narrow, cross-category phrase/keyword
 *     match (mirrors AiHandoffService::customerRequestedHuman()'s
 *     "specific enough to essentially only fire on a real case" design)
 *     that flags a message as potentially describing a problem with
 *     something already bought — "সমস্যা হচ্ছে", "কাজ করছে না", "রং উঠে
 *     গেছে", "not working", "broken", etc. This is a SIGNAL to the model,
 *     not a hard gate — the model still decides how to actually respond,
 *     following the "Post-purchase concerns" rules baked into
 *     config('ai.system_prompt').
 *
 *  2. verifiedPurchase() — checks whether the product currently being
 *     discussed (resolved the same way every other AI service in this
 *     pipeline resolves "what product" — a literal name match against
 *     the conversation, current message + recent history) actually
 *     appears in this SPECIFIC, channel-verified customer's own real
 *     order_items — never a guess, never inferred from the complaint
 *     text itself. order_items.product_name is a point-of-sale snapshot
 *     (schema.sql), so this needs no join back to the live product
 *     catalog and survives a since-renamed/deleted product.
 *
 * Both methods use the SAME channel-verified identifier
 * (AiCustomerMemoryService's messenger_psid/customer_phone convention)
 * as the rest of the customer-context pipeline — never a value the
 * customer typed in chat text — so this can never be used to read back
 * a different customer's purchase history.
 */
class AiPostPurchaseContextService
{
    /**
     * Deliberately generic across product categories — no skincare/
     * cosmetics-specific wording. Bengali, Banglish, and English.
     */
    protected const CONCERN_PHRASES = [
        'সমস্যা হচ্ছে', 'সমস্যা হয়েছে', 'সমস্যা করছে', 'সমস্যা পাচ্ছি',
        'কাজ করছে না', 'কাজ করে না', 'চলছে না', 'চার্জ নিচ্ছে না',
        'নষ্ট হয়ে গেছে', 'নষ্ট হয়ে গেসে', 'ভেঙে গেছে', 'ভাঙা অবস্থায়',
        'ছিঁড়ে গেছে', 'ছিড়ে গেছে', 'রং উঠে গেছে', 'কালার উঠে গেছে',
        'এলার্জি হয়েছে', 'র‍্যাশ হয়েছে', 'জ্বালাপোড়া করছে', 'জ্বালা করছে',
        'ফেরত দিতে চাই', 'রিটার্ন করতে চাই', 'এক্সচেঞ্জ করতে চাই',
        'ব্যবহার করার পর সমস্যা', 'ব্যবহার করে সমস্যা', 'নেওয়ার পর সমস্যা',
        'খাওয়ার পর সমস্যা', 'পরার পর সমস্যা',
        'not working', 'stopped working', "doesn't work", 'does not work',
        'is broken', 'came broken', 'arrived broken', 'damaged', 'defective', 'faulty',
        'issue after using', 'problem after using', 'problem after i used',
        'reaction after using', 'want to return', 'want a refund', 'want to exchange',
    ];

    /** Deterministic, heuristic phrase match — see class docblock #1. */
    public function isPostPurchaseConcern(?string $message): bool
    {
        if (! $message || trim($message) === '') {
            return false;
        }

        $haystack = mb_strtolower($message);

        foreach (self::CONCERN_PHRASES as $phrase) {
            if (str_contains($haystack, mb_strtolower($phrase))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $conversationTexts  Current message + recent history —
     *                                                 same shape AiProductKnowledgeService::relevantProducts() takes.
     * @return string|null See verifiedPurchase()'s docblock.
     */
    public function forMessengerCustomer(int $tenantId, string $psid, array $conversationTexts): ?string
    {
        return $this->verifiedPurchase($tenantId, 'messenger_psid', $psid, $conversationTexts);
    }

    /**
     * @param  array<int, string>  $conversationTexts  Current message + recent history.
     * @return string|null See verifiedPurchase()'s docblock.
     */
    public function forWhatsAppCustomer(int $tenantId, string $waId, array $conversationTexts): ?string
    {
        return $this->verifiedPurchase($tenantId, 'customer_phone', $this->normalizePhone($waId), $conversationTexts);
    }

    /**
     * @param  string  $column  'messenger_psid' or 'customer_phone' — same
     *                          channel-verified columns AiCustomerMemoryService::latestOrder() uses;
     *                          $value must already be the verified identifier, never customer-typed text.
     * @param  array<int, string>  $conversationTexts  Current message + recent history —
     *                                                 same shape AiProductKnowledgeService::relevantProducts() takes, so a
     *                                                 product named earlier in the conversation (not just the current
     *                                                 message) is still found.
     * @return string|null A verified, ready-to-state fact ("Verified: ...") when a real order of
     *                      this customer's contains a product mentioned in the conversation; null when nothing
     *                      verifies (no match, no orders, or a lookup failure) — the caller is responsible for
     *                      telling the model that absence itself means "do not assume a purchase."
     */
    protected function verifiedPurchase(int $tenantId, string $column, string $value, array $conversationTexts): ?string
    {
        $haystack = mb_strtolower(implode(' ', array_filter($conversationTexts)));

        if (trim($haystack) === '') {
            return null;
        }

        try {
            $orders = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where($column, $value)
                ->with(['items' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderByDesc('created_at')
                ->limit((int) config('ai.purchase_verification_order_scan_limit', 20))
                ->get();
        } catch (\Throwable $e) {
            Log::warning('AI post-purchase context: failed to look up the customer\'s order items — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return null;
        }

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if (! $item->product_name) {
                    continue;
                }

                if (str_contains($haystack, mb_strtolower($item->product_name))) {
                    $date = $order->created_at?->format('Y-m-d') ?? 'an earlier date';

                    return "Verified: this customer's order {$order->order_number} (status: {$order->status}, placed {$date}) included \"{$item->product_name}\".";
                }
            }
        }

        return null;
    }

    /** Mirrors AiCustomerMemoryService::normalizePhone() exactly — same convention FraudChecker::normalizePhone() already establishes for this codebase's one local phone format (leading 0, no country code). */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '880')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone;
    }
}
