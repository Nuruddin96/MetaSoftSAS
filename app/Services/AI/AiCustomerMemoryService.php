<?php

namespace App\Services\AI;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Phase 6 — "customer memory": what this business already knows about the
 * SPECIFIC person on the other end of the current Messenger/WhatsApp
 * conversation, so the AI never has to make them repeat their own order
 * number, delivery address, or phone number.
 *
 * Deliberately NOT the same thing as App\Services\AI\Tools\CustomerLookupTool
 * (search by typed phone/name) — that tool's own docblock explains exactly
 * why it must never be reachable from the public Messenger/WhatsApp flow:
 * an anonymous visitor could type a DIFFERENT customer's phone number or
 * name and read back their address/spend history. Every lookup here instead
 * keys off an identifier the channel itself verified, never one a customer
 * typed into the chat:
 *
 *  - Messenger: orders.messenger_psid — written by MessengerWebhookController
 *    only at the moment THIS exact psid completed a real checkout (see
 *    chunk24.sql), so matching on it can never surface another customer's
 *    order no matter what the current message says.
 *  - WhatsApp: wa_id is the sender's own WhatsApp-registered phone number,
 *    authenticated by Meta on every inbound webhook call — never a value
 *    the conversation text supplied — so it is matched against
 *    orders.customer_phone the same way FraudChecker::internalHistory()
 *    already trusts a phone number, after the same 880-prefix
 *    normalization that codebase already uses.
 *
 * Only the single most recent order is surfaced, and only order_number/
 * status/delivery address — never payment/due-balance/spend-history detail
 * (that stays exclusive to the authenticated tool). No AI call, no
 * free-text "preference" extraction: everything returned here is a fact
 * already stored verbatim in the orders table, never a summary this class
 * invents.
 */
class AiCustomerMemoryService
{
    public function forMessengerCustomer(int $tenantId, string $psid): string
    {
        return $this->format($this->latestOrder($tenantId, 'messenger_psid', $psid));
    }

    public function forWhatsAppCustomer(int $tenantId, string $waId): string
    {
        return $this->format($this->latestOrder($tenantId, 'customer_phone', $this->normalizePhone($waId)));
    }

    protected function latestOrder(int $tenantId, string $column, string $value): ?Order
    {
        try {
            return Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where($column, $value)
                ->latest()
                ->first();
        } catch (\Throwable $e) {
            Log::warning('AI customer memory: failed to look up the customer\'s order history — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return null;
        }
    }

    protected function format(?Order $order): string
    {
        if (! $order) {
            return '';
        }

        $line = "Most recent order: {$order->order_number}, status: {$order->status}";

        if ($order->customer_address) {
            $line .= ", delivery address on file: {$order->customer_address}";
        }

        return $line;
    }

    /** Same convention FraudChecker::normalizePhone() already establishes for this codebase's one local phone format (leading 0, no country code). */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '880')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone;
    }
}
