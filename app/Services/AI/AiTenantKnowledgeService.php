<?php

namespace App\Services\AI;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4 — the "RELEVANT BUSINESS KNOWLEDGE" layer, deliberately separate
 * from both AiConversationStyleService (tone, not facts) and the
 * tenant-authored free-text ai_custom_instructions (Phase 3, follows the
 * tenant's own wording but is never guaranteed accurate/current). This
 * class only ever surfaces data that is ALREADY authoritative elsewhere in
 * the app — never a second place tenants have to type the same fact into.
 *
 * Deliberately does NOT introduce a new "business info" form (address,
 * phone, opening hours, payment methods, policies, FAQ) — none of that
 * exists anywhere in this schema today, and the Phase 3 free-text
 * instructions field already flows into the exact same prompt and can
 * fully express all of it for a small merchant, without duplicating UI or
 * schema for the same underlying "tenant-authored text reaches the
 * prompt" mechanism. The one thing genuinely worth wiring in here is real,
 * already-existing structured data — today that's delivery charge
 * (Tenant::booted() seeds delivery_charge_inside_dhaka/
 * delivery_charge_outside_dhaka/currency for every tenant on creation, and
 * the SAME rows back real order/checkout delivery-fee calculation
 * elsewhere in the app) — never a second, AI-only copy of it.
 */
class AiTenantKnowledgeService
{
    /**
     * A short, factual line built ONLY from data the app itself already
     * treats as authoritative — never invented, never tenant free text.
     * '' when nothing is set yet or the lookup itself fails; the caller
     * (AiAgentService) simply omits this section of the prompt in that
     * case, same "empty is a normal case, not an error" contract as
     * AiConversationStyleService::messengerStyleExamples().
     */
    public function businessKnowledge(int $tenantId): string
    {
        try {
            $settings = StoreSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('key', ['delivery_charge_inside_dhaka', 'delivery_charge_outside_dhaka', 'currency'])
                ->pluck('value', 'key');
        } catch (\Throwable $e) {
            Log::warning('AI tenant knowledge: failed to read business data — continuing without it.', [
                'tenant_id' => $tenantId,
                'exception' => get_class($e),
            ]);

            return '';
        }

        $currency = $settings['currency'] ?? 'BDT';
        $lines = [];

        if (isset($settings['delivery_charge_inside_dhaka'])) {
            $lines[] = "Delivery charge inside Dhaka: {$settings['delivery_charge_inside_dhaka']} {$currency}.";
        }

        if (isset($settings['delivery_charge_outside_dhaka'])) {
            $lines[] = "Delivery charge outside Dhaka: {$settings['delivery_charge_outside_dhaka']} {$currency}.";
        }

        return implode(' ', $lines);
    }
}
