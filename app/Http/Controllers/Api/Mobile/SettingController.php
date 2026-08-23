<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Services\DeliveryChargeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Delivery-charge + brand settings — the smallest, highest-value slice of
 * Tenant\SettingController/WebsiteController's real surface (Priority 3
 * parity pass). This mobile controller deliberately does NOT mirror
 * courier-credential connect/marketing-pixel/AI-agent-toggle/domain-request
 * or the wider website builder (homepage/footer/banners/pages/reviews) —
 * courier/pixel are OAuth- or credential-heavy flows out of scope for a
 * small parity slice, the AI-agent toggles share `store_settings` keys
 * (messenger_ai_auto_reply_enabled/whatsapp_ai_auto_reply_enabled) with the
 * separate, currently in-progress WhatsApp/AI-agent work elsewhere in this
 * codebase (left untouched per explicit instruction), and the rest of the
 * website builder has no practical mobile UI — brand (logo/colors/
 * announcement) is the one piece Dashboard's own onboarding checklist
 * already links to from mobile.
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

    /** Mirrors the fields Tenant\WebsiteController::index() feeds its brand card. */
    public function brand()
    {
        $tenant = app('currentTenant');
        $set = StoreSetting::pluck('value', 'key');

        return response()->json([
            'store_name' => $tenant->store_name,
            'primary_color' => $tenant->primary_color ?: '#128155',
            'secondary_color' => $tenant->secondary_color,
            'logo_url' => $tenant->logo_path ? asset('storage/'.$tenant->logo_path) : null,
            'announcement' => $set['announcement'] ?? null,
            'announcement_style' => $set['announcement_style'] ?? 'static',
        ]);
    }

    /** Mirrors Tenant\WebsiteController::brand() exactly (logo/colors/announcement). */
    public function updateBrand(Request $request)
    {
        $data = $request->validate([
            'store_name' => 'required|string|max:150',
            'primary_color' => 'required|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_color' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/',
            'logo' => 'nullable|image|max:2048',
            'announcement' => 'nullable|string|max:200',
            'announcement_style' => 'nullable|in:static,marquee',
        ]);

        $tenant = app('currentTenant');

        $update = [
            'store_name' => $data['store_name'],
            'primary_color' => $data['primary_color'],
            'secondary_color' => $data['secondary_color'] ?? $tenant->secondary_color,
        ];

        if ($request->hasFile('logo')) {
            if ($tenant->logo_path) {
                Storage::disk('public')->delete($tenant->logo_path);
            }
            $update['logo_path'] = $request->file('logo')->store('branding/'.$tenant->id, 'public');
        }

        $tenant->update($update);

        StoreSetting::updateOrCreate(['key' => 'announcement'], ['value' => $data['announcement'] ?? null]);
        StoreSetting::updateOrCreate(['key' => 'announcement_style'], ['value' => $data['announcement_style'] ?? 'static']);

        return response()->json(['ok' => true]);
    }
}
