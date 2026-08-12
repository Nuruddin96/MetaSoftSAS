<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CourierSetting;
use App\Models\FacebookConnection;
use App\Models\FacebookPage;
use App\Models\MarketingSetting;
use App\Models\MessengerSetting;
use App\Models\StoreSetting;
use App\Models\WhatsAppBusinessAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Services\Domain\DomainManager;
use App\Services\WhatsApp\WhatsAppOAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $tenant = app('currentTenant');

        // Facebook OAuth tables are additive (database/sql/chunk23.sql) —
        // don't query them at all if they haven't been imported yet, so the
        // rest of this page (courier/marketing/domain, the manual Messenger
        // card) keeps working exactly as before rather than 500ing.
        $facebookReady = FacebookPage::tablesReady();

        // Same additive-table guard for WhatsApp (database/sql/chunk26.sql).
        $whatsappReady = WhatsAppPhoneNumber::tablesReady();
        $whatsappAccount = $whatsappReady ? WhatsAppBusinessAccount::first() : null;
        $whatsappPhoneNumbers = $whatsappReady ? WhatsAppPhoneNumber::orderByDesc('id')->get() : collect();

        // Embedded into the Connect/Reconnect WhatsApp button for the
        // Settings page's JS to submit back with the signup result — minted
        // whenever the tenant might plausibly click that button (no
        // connection at all, or an existing one that needs reconnecting/
        // retrying), never when a fully active connection already exists,
        // since it would be meaningless there. currentOrNewState() also
        // avoids leaving a fresh unused row behind on every page view a
        // tenant makes without clicking Connect — see its docblock.
        $hasFullyActiveNumber = $whatsappPhoneNumbers->contains(
            fn ($p) => $p->is_active && $p->status === 'active'
        );
        $whatsappConnectState = ($whatsappReady && ! $hasFullyActiveNumber)
            ? (new WhatsAppOAuthService)->currentOrNewState($tenant, auth('tenant')->user())
            : null;

        return view('tenant.settings', [
            'messenger' => MessengerSetting::first(),
            'facebookConnection' => $facebookReady ? FacebookConnection::first() : null,
            'facebookPages' => $facebookReady ? FacebookPage::orderByDesc('id')->get() : collect(),
            'whatsappAccount' => $whatsappAccount,
            'whatsappPhoneNumbers' => $whatsappPhoneNumbers,
            'whatsappConnectState' => $whatsappConnectState,
            // Cosmetic mirror of the server-side 'feature:whatsapp' route
            // gate (EnsureFeatureEnabled) — this only decides what the
            // Connect button looks like; the actual enforcement lives in the
            // middleware, same "UI reflects it, server enforces it" split as
            // the custom-domain card below (($tenant->plan?->allow_custom_domain).
            'whatsappFeatureEnabled' => (bool) $tenant->plan?->hasFeature('whatsapp'),
            'tenant' => $tenant,
            'couriers' => CourierSetting::get()->keyBy('provider'),
            'marketing' => MarketingSetting::firstOrNew(['tenant_id' => app('currentTenant')->id]),
            'store' => StoreSetting::pluck('value', 'key'),
            'domainTxtValue' => $tenant->custom_domain_verification_token
                ? DomainManager::expectedTxtValue($tenant->custom_domain_verification_token)
                : null,
        ]);
    }

    public function courier(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|in:steadfast,pathao',
            'credentials' => 'required|array',
            'is_active' => 'nullable|boolean',
        ]);

        // drop empty fields so we don't overwrite saved secrets with blanks
        $credentials = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');

        $setting = CourierSetting::firstOrNew(['provider' => $data['provider']]);
        $setting->credentials = array_merge($setting->credentials ?? [], $credentials);
        $setting->is_active = $request->boolean('is_active');
        $setting->save();

        return back()->with('success', ucfirst($data['provider']).' সেটিংস সেভ হয়েছে।');
    }

    public function messenger(Request $request)
    {
        $data = $request->validate([
            'page_id' => 'required|string|max:50',
            'page_access_token' => 'nullable|string',
            'page_name' => 'nullable|string|max:150',
        ]);

        $tenant = app('currentTenant');

        // A tenant could otherwise type in ANY page_id — including one a
        // different tenant already has connected — with no verification of
        // actual ownership. The webhook's lookup (WHERE page_id = ? LIMIT 1)
        // has no way to know which claim is legitimate, so a real customer's
        // conversation could get filed under the wrong tenant's inbox.
        $claimedByAnotherTenant = MessengerSetting::withoutGlobalScopes()
            ->where('page_id', $data['page_id'])
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($claimedByAnotherTenant) {
            return back()
                ->with('error', 'এই Facebook Page ID ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে। Page ID-টি সঠিক কিনা যাচাই করুন।')
                ->withInput();
        }

        $setting = MessengerSetting::firstOrNew([]);
        $setting->page_id = $data['page_id'];
        $setting->page_name = $data['page_name'] ?? null;

        if (! empty($data['page_access_token'])) {
            $setting->page_access_token = $data['page_access_token'];
        }

        $setting->tenant_id = $tenant->id;
        $setting->is_active = $request->boolean('is_active', true);

        try {
            $setting->save();
        } catch (QueryException $e) {
            // Race condition: another tenant claimed this exact page_id between
            // the check above and this save. The DB's unique constraint on
            // page_id (chunk21.sql) is the real guarantee; this just turns the
            // resulting integrity-violation exception into a normal message
            // instead of a 500.
            if ($e->getCode() === '23000') {
                return back()
                    ->with('error', 'এই Facebook Page ID ইতিমধ্যে অন্য একটি স্টোরে যুক্ত করা আছে। Page ID-টি সঠিক কিনা যাচাই করুন।')
                    ->withInput();
            }

            throw $e;
        }

        return back()->with('success', 'মেসেঞ্জার পেজ কানেক্ট করা হয়েছে।');
    }

    public function marketing(Request $request)
    {
        $data = $request->validate([
            'fb_pixel_id' => 'nullable|string|max:50',
            'fb_capi_token' => 'nullable|string',
            'fb_test_event_code' => 'nullable|string|max:50',
            'gtm_container_id' => 'nullable|string|max:20',
            'meta_app_id' => 'nullable|string|max:50',
            'meta_app_secret' => 'nullable|string',
            'meta_access_token' => 'nullable|string',
            'meta_ad_account_id' => 'nullable|string|max:50',
        ]);

        $setting = MarketingSetting::firstOrNew(['tenant_id' => app('currentTenant')->id]);

        foreach ($data as $key => $value) {
            // blank secret fields keep their saved value
            if (in_array($key, ['fb_capi_token', 'meta_app_secret', 'meta_access_token']) && $value === null) {
                continue;
            }
            $setting->{$key} = $value;
        }

        $setting->tenant_id = app('currentTenant')->id;
        $setting->updated_at = now();
        $setting->save();

        return back()->with('success', 'মার্কেটিং সেটিংস সেভ হয়েছে।');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'delivery_charge_inside_dhaka' => 'required|numeric|min:0',
            'delivery_charge_outside_dhaka' => 'required|numeric|min:0',
        ]);

        foreach ($data as $key => $value) {
            StoreSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return back()->with('success', 'স্টোর সেটিংস সেভ হয়েছে।');
    }

    /**
     * AI Customer Support Agent ON/OFF toggle (Phase 1/2). Reuses
     * store_settings the same way store() above does for delivery charges
     * — no dedicated table for a single boolean. StoreSetting's
     * BelongsToTenant scope (and updateOrCreate's own tenant_id auto-fill
     * on create) is what makes this tenant-isolated: this method never
     * needs to name a tenant_id itself, and there is no way for this
     * request to reach or modify another tenant's row.
     */
    public function aiAgent(Request $request)
    {
        $data = $request->validate([
            'ai_agent_enabled' => 'nullable|boolean',
        ]);

        StoreSetting::updateOrCreate(
            ['key' => 'ai_agent_enabled'],
            ['value' => $request->boolean('ai_agent_enabled') ? '1' : '0']
        );

        return back()->with('success', 'AI এজেন্ট সেটিংস সেভ হয়েছে।');
    }

    /** Tenant requests their own domain (e.g. myshop.com); super admin approves manually. */
    public function requestDomain(Request $request)
    {
        $tenant = app('currentTenant');

        if (! $tenant->plan?->allow_custom_domain) {
            return back()->with('error', 'কাস্টম ডোমেইন ফিচারটি আপনার প্ল্যানে নেই। Pro প্ল্যানে আপগ্রেড করুন।');
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

        return back()->with('success', 'ডোমেইন রিকোয়েস্ট পাঠানো হয়েছে — DNS TXT রেকর্ড যোগ করুন, আমাদের টিম যাচাই করে চালু করে দেবে।');
    }
}
