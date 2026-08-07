<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\CourierSetting;
use App\Models\MarketingSetting;
use App\Models\MessengerSetting;
use App\Models\StoreSetting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        return view('tenant.settings', [
            'messenger' => MessengerSetting::first(),
            'tenant'   => app('currentTenant'),
            'couriers' => CourierSetting::get()->keyBy('provider'),
            'marketing' => MarketingSetting::firstOrNew(['tenant_id' => app('currentTenant')->id]),
            'store'    => StoreSetting::pluck('value', 'key'),
        ]);
    }

    public function courier(Request $request)
    {
        $data = $request->validate([
            'provider'    => 'required|in:steadfast,pathao',
            'credentials' => 'required|array',
            'is_active'   => 'nullable|boolean',
        ]);

        // drop empty fields so we don't overwrite saved secrets with blanks
        $credentials = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');

        $setting = CourierSetting::firstOrNew(['provider' => $data['provider']]);
        $setting->credentials = array_merge($setting->credentials ?? [], $credentials);
        $setting->is_active   = $request->boolean('is_active');
        $setting->save();

        return back()->with('success', ucfirst($data['provider']) . ' সেটিংস সেভ হয়েছে।');
    }

    public function messenger(Request $request)
    {
        $data = $request->validate([
            'page_id'            => 'required|string|max:50',
            'page_access_token'  => 'nullable|string',
            'page_name'          => 'nullable|string|max:150',
        ]);

        $setting = MessengerSetting::firstOrNew([]);
        $setting->page_id   = $data['page_id'];
        $setting->page_name = $data['page_name'] ?? null;

        if (! empty($data['page_access_token'])) {
            $setting->page_access_token = $data['page_access_token'];
        }

        $setting->tenant_id  = app('currentTenant')->id;
        $setting->is_active  = $request->boolean('is_active', true);
        $setting->save();

        return back()->with('success', 'মেসেঞ্জার পেজ কানেক্ট করা হয়েছে।');
    }

    public function marketing(Request $request)
    {
        $data = $request->validate([
            'fb_pixel_id'         => 'nullable|string|max:50',
            'fb_capi_token'       => 'nullable|string',
            'fb_test_event_code'  => 'nullable|string|max:50',
            'gtm_container_id'    => 'nullable|string|max:20',
            'meta_app_id'         => 'nullable|string|max:50',
            'meta_app_secret'     => 'nullable|string',
            'meta_access_token'   => 'nullable|string',
            'meta_ad_account_id'  => 'nullable|string|max:50',
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
            'delivery_charge_inside_dhaka'  => 'required|numeric|min:0',
            'delivery_charge_outside_dhaka' => 'required|numeric|min:0',
        ]);

        foreach ($data as $key => $value) {
            StoreSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return back()->with('success', 'স্টোর সেটিংস সেভ হয়েছে।');
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
            'custom_domain_requested'      => strtolower($data['custom_domain_requested']),
            'custom_domain_request_status' => 'pending',
        ]);

        return back()->with('success', 'ডোমেইন রিকোয়েস্ট পাঠানো হয়েছে — অ্যাডমিন যাচাই করে চালু করে দেবে।');
    }
}
