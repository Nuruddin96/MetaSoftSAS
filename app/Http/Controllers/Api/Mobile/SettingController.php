<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CourierSetting;
use App\Models\MarketingSetting;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Services\DeliveryChargeService;
use App\Services\Domain\CloudflareDomainService;
use App\Services\Domain\DomainManager;
use App\Services\Marketing\MetaCapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Delivery-charge + brand + courier + marketing-pixel + website-builder
 * (homepage/footer/domain) settings — the real surface of
 * Tenant\SettingController/WebsiteController mirrored to mobile (Priority 3
 * parity pass; homepage/footer/domain added in the Website Builder parity
 * task). This mobile controller deliberately does NOT mirror the
 * AI-agent-toggle fields — they share `store_settings` keys
 * (messenger_ai_auto_reply_enabled/whatsapp_ai_auto_reply_enabled) with the
 * separate, currently in-progress WhatsApp/AI-agent work elsewhere in this
 * codebase (left untouched per explicit instruction) — nor the tenant
 * `theme` column, which Web itself has no settings screen for (it's only
 * ever set once, automatically, from the onboarding wizard's business-type
 * selection — see TenantOnboardingService — so there's no web behavior to
 * mirror). Banners/pages/reviews live in their own dedicated
 * BannerController/PageController/ReviewController.
 */
class SettingController extends Controller
{
    /** Credential field names per provider — matches Courier\CourierManager::make() exactly. */
    private const COURIER_FIELDS = [
        'steadfast' => ['api_key', 'secret_key'],
        'pathao' => ['client_id', 'client_secret', 'username', 'password', 'store_id'],
    ];

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

    /**
     * Read-only, masked courier connection status for both providers —
     * mirrors the settings blade's per-field "(সেভ করা আছে — বদলাতে চাইলে
     * লিখুন)" placeholder logic (`! empty($creds['x'])`) so Flutter can show
     * the same "already saved, blank to keep" hint per field, without this
     * endpoint ever returning a decrypted credential value itself.
     */
    public function courier()
    {
        $settings = CourierSetting::get()->keyBy('provider');

        $result = [];
        foreach (self::COURIER_FIELDS as $provider => $fields) {
            $setting = $settings->get($provider);
            $creds = $setting->credentials ?? [];

            $result[$provider] = [
                'is_active' => (bool) ($setting->is_active ?? false),
                'fields' => collect($fields)->mapWithKeys(
                    fn ($field) => [$field => ! empty($creds[$field])]
                ),
            ];
        }

        return response()->json($result);
    }

    /** Mirrors Tenant\SettingController::courier() exactly (blank fields keep the saved secret). */
    public function updateCourier(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|in:steadfast,pathao',
            'credentials' => 'required|array',
            'is_active' => 'nullable|boolean',
        ]);

        $credentials = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');

        $setting = CourierSetting::firstOrNew(['provider' => $data['provider']]);
        $setting->credentials = array_merge($setting->credentials ?? [], $credentials);
        $setting->is_active = $request->boolean('is_active');
        $setting->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Read-only, masked Marketing Pixel/CAPI/GTM configuration — mirrors
     * only the 5 fields Tenant\SettingController::marketing() actually uses
     * anywhere in this codebase (fb_pixel_id, fb_capi_token,
     * fb_test_event_code, capi_test_mode, gtm_container_id). The web
     * method also validates/persists meta_app_id/meta_app_secret/
     * meta_access_token/meta_ad_account_id, but nothing reads them — no
     * service, no view — so they're deliberately omitted here rather than
     * mirroring dead surface. fb_capi_token is never returned raw, only
     * whether one is saved. The capi_last_* fields are read-only telemetry
     * set by real Purchase-event sends during checkout (MetaCapiService),
     * not by testMarketingCapi() below.
     */
    public function marketing()
    {
        $marketing = MarketingSetting::first();

        return response()->json([
            'fb_pixel_id' => $marketing->fb_pixel_id ?? null,
            'gtm_container_id' => $marketing->gtm_container_id ?? null,
            'fb_test_event_code' => $marketing->fb_test_event_code ?? null,
            'capi_test_mode' => (bool) ($marketing->capi_test_mode ?? false),
            'fb_capi_token_saved' => ! empty($marketing?->fb_capi_token),
            'capi_last_status' => $marketing->capi_last_status ?? null,
            'capi_last_http_status' => $marketing->capi_last_http_status ?? null,
            'capi_last_error' => $marketing->capi_last_error ?? null,
            'capi_last_event_at' => $marketing?->capi_last_event_at?->toIso8601String(),
        ]);
    }

    /** Mirrors Tenant\SettingController::marketing() exactly for the 5 real fields (blank fb_capi_token keeps the saved secret; every other field is a literal overwrite, matching the web method's own behavior). */
    public function updateMarketing(Request $request)
    {
        $data = $request->validate([
            'fb_pixel_id' => 'nullable|string|max:50',
            'fb_capi_token' => 'nullable|string',
            'fb_test_event_code' => 'nullable|string|max:50',
            'gtm_container_id' => 'nullable|string|max:20',
            'capi_test_mode' => 'nullable|boolean',
        ]);

        $setting = MarketingSetting::firstOrNew(['tenant_id' => app('currentTenant')->id]);

        $setting->fb_pixel_id = $data['fb_pixel_id'] ?? null;
        $setting->gtm_container_id = $data['gtm_container_id'] ?? null;
        $setting->fb_test_event_code = $data['fb_test_event_code'] ?? null;

        if (! empty($data['fb_capi_token'])) {
            $setting->fb_capi_token = $data['fb_capi_token'];
        }

        $setting->capi_test_mode = $request->boolean('capi_test_mode');
        $setting->tenant_id = app('currentTenant')->id;
        $setting->updated_at = now();
        $setting->save();

        return response()->json(['ok' => true]);
    }

    /** Mirrors Tenant\SettingController::testCapiConnection() exactly. */
    public function testMarketingCapi()
    {
        $mk = MarketingSetting::first();

        if (! $mk || ! $mk->fb_pixel_id || ! $mk->fb_capi_token) {
            return response()->json([
                'success' => false,
                'message' => 'Pixel ID ও Conversion API Access Token দুটোই আগে সেভ করুন।',
            ]);
        }

        $result = (new MetaCapiService($mk->fb_pixel_id, $mk->fb_capi_token))->testConnection();

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
        ]);
    }

    /** Mirrors the fields Tenant\WebsiteController::index() feeds its homepage card. */
    public function homepage()
    {
        $set = StoreSetting::pluck('value', 'key');

        return response()->json([
            'hero_style' => $set['hero_style'] ?? 'slider',
            'show_categories' => ($set['show_categories'] ?? '1') === '1',
            'show_featured' => ($set['show_featured'] ?? '1') === '1',
            'featured_title' => $set['featured_title'] ?? 'আমাদের প্রোডাক্ট',
        ]);
    }

    /** Mirrors Tenant\WebsiteController::homepage() exactly. */
    public function updateHomepage(Request $request)
    {
        $data = $request->validate([
            'featured_title' => 'nullable|string|max:100',
            'hero_style' => 'nullable|in:slider,simple,none',
        ]);

        StoreSetting::updateOrCreate(['key' => 'featured_title'], ['value' => $data['featured_title'] ?? null]);
        StoreSetting::updateOrCreate(['key' => 'hero_style'], ['value' => $data['hero_style'] ?? 'slider']);
        StoreSetting::updateOrCreate(['key' => 'show_featured'], ['value' => $request->boolean('show_featured') ? '1' : '0']);
        StoreSetting::updateOrCreate(['key' => 'show_categories'], ['value' => $request->boolean('show_categories') ? '1' : '0']);

        return response()->json(['ok' => true]);
    }

    /** Mirrors the fields Tenant\WebsiteController::index() feeds its footer card. */
    public function footer()
    {
        $tenant = app('currentTenant');
        $set = StoreSetting::pluck('value', 'key');

        return response()->json([
            'footer_about' => $set['footer_about'] ?? null,
            'footer_phone' => $set['footer_phone'] ?? $tenant->owner_phone,
            'footer_email' => $set['footer_email'] ?? null,
            'footer_address' => $set['footer_address'] ?? null,
            'footer_note' => $set['footer_note'] ?? null,
            'social_facebook' => $set['social_facebook'] ?? null,
            'social_instagram' => $set['social_instagram'] ?? null,
            'social_youtube' => $set['social_youtube'] ?? null,
            'social_tiktok' => $set['social_tiktok'] ?? null,
            'whatsapp_number' => $set['whatsapp_number'] ?? null,
            'show_whatsapp_float' => ($set['show_whatsapp_float'] ?? '0') === '1',
        ]);
    }

    /** Mirrors Tenant\WebsiteController::footer() exactly. */
    public function updateFooter(Request $request)
    {
        $data = $request->validate([
            'footer_about' => 'nullable|string|max:500',
            'footer_phone' => 'nullable|string|max:50',
            'footer_email' => 'nullable|string|max:100',
            'footer_address' => 'nullable|string|max:255',
            'footer_note' => 'nullable|string|max:255',
            'social_facebook' => 'nullable|url|max:255',
            'social_instagram' => 'nullable|url|max:255',
            'social_youtube' => 'nullable|url|max:255',
            'social_tiktok' => 'nullable|url|max:255',
            'whatsapp_number' => 'nullable|string|max:20',
        ]);

        foreach ($data as $key => $value) {
            StoreSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        StoreSetting::updateOrCreate(['key' => 'show_whatsapp_float'], ['value' => $request->boolean('show_whatsapp_float') ? '1' : '0']);

        return response()->json(['ok' => true]);
    }

    /**
     * Read-only domain status — mirrors Tenant\SettingController::index()'s
     * domain card, via Tenant::customDomainConnectionStatus() (the granular,
     * Cloudflare-aware superset of the 5-state customDomainDisplayStatus()
     * the web card itself uses). dns_record is always returned (same 3
     * fields the web card's DNS-required box shows) so Flutter doesn't need
     * to know the Cloudflare/CNAME details, only render them when
     * dns_required is true.
     */
    public function domain()
    {
        $tenant = app('currentTenant');

        return response()->json([
            'allow_custom_domain' => (bool) $tenant->plan?->allow_custom_domain,
            'status' => $tenant->customDomainConnectionStatus(),
            'custom_domain' => $tenant->custom_domain,
            'custom_domain_requested' => $tenant->custom_domain_requested,
            'dns_required' => Tenant::cloudflareDomainColumnsReady()
                && $tenant->custom_domain_connect_status === 'dns_required',
            'dns_record' => [
                'type' => 'CNAME',
                'host' => '@ (অথবা www)',
                'target' => app(CloudflareDomainService::class)->fallbackOriginTarget(),
            ],
        ]);
    }

    /** Mirrors Tenant\SettingController::requestDomain() exactly. */
    public function requestDomain(Request $request)
    {
        $tenant = app('currentTenant');

        if (! $tenant->plan?->allow_custom_domain) {
            return response()->json(['message' => 'কাস্টম ডোমেইন ফিচারটি আপনার প্ল্যানে নেই। Pro প্ল্যানে আপগ্রেড করুন।'], 422);
        }

        if ($tenant->custom_domain_verified && $tenant->custom_domain) {
            return response()->json(['message' => 'আপনার একটি সক্রিয় কাস্টম ডোমেইন আছে। পরিবর্তনের প্রয়োজন হলে অ্যাডমিনের সাথে যোগাযোগ করুন।'], 422);
        }

        $data = $request->validate([
            'custom_domain_requested' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ], [
            'custom_domain_requested.regex' => 'সঠিক ডোমেইন দিন, যেমন: myshop.com',
        ]);

        $tenant->update([
            'custom_domain_requested' => strtolower($data['custom_domain_requested']),
            'custom_domain_request_status' => 'pending',
            'custom_domain_verification_token' => DomainManager::generateVerificationToken(),
            'custom_domain_dns_verified_at' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Mirrors Tenant\SettingController::cancelDomainRequest() exactly. */
    public function cancelDomain()
    {
        $tenant = app('currentTenant');

        if (! in_array($tenant->custom_domain_request_status, ['pending', 'dns_verified', 'approved'], true)) {
            return response()->json(['message' => 'বাতিল করার মতো কোনো পেন্ডিং ডোমেইন রিকোয়েস্ট নেই।'], 422);
        }

        $tenant->update([
            'custom_domain_requested' => null,
            'custom_domain_request_status' => 'none',
            'custom_domain_verification_token' => null,
            'custom_domain_dns_verified_at' => null,
        ]);

        return response()->json(['ok' => true]);
    }
}
