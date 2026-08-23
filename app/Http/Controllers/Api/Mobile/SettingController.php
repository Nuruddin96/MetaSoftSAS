<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Services\DeliveryChargeService;
use Illuminate\Http\Request;

/**
 * Delivery-charge settings ONLY — the smallest, highest-value slice of
 * Tenant\SettingController's real surface (Priority 3 parity pass). This
 * mobile controller deliberately does NOT mirror courier-credential
 * connect/marketing-pixel/AI-agent-toggle/domain-request — courier/pixel
 * are OAuth- or credential-heavy flows out of scope for a small parity
 * slice, and the AI-agent toggles share `store_settings` keys
 * (messenger_ai_auto_reply_enabled/whatsapp_ai_auto_reply_enabled) with
 * the separate, currently in-progress WhatsApp/AI-agent work elsewhere in
 * this codebase — left untouched per explicit instruction not to touch
 * that WIP.
 */
class SettingController extends Controller
{
    public function index(DeliveryChargeService $deliveryCharge)
    {
        // Re-shaped to snake_case, round-trippable field names (matching
        // store()'s own input keys) rather than chargesForView()'s
        // camelCase shape, which exists purely for embedding into a Blade
        // view's inline JS — not meant as a REST response contract.
        $charges = $deliveryCharge->chargesForView();

        return response()->json([
            'delivery_charge_inside_dhaka' => $charges['chargeInside'],
            'delivery_charge_outside_dhaka' => $charges['chargeOutside'],
        ]);
    }

    /** Mirrors Tenant\SettingController::store()'s delivery-charge fields exactly. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'delivery_charge_inside_dhaka' => 'required|numeric|min:0',
            'delivery_charge_outside_dhaka' => 'required|numeric|min:0',
        ]);

        foreach ($data as $key => $value) {
            StoreSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['ok' => true]);
    }
}
