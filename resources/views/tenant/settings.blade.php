@extends('layouts.panel')

@section('title', 'সেটিংস')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">সেটিংস</h1>

<div class="grid lg:grid-cols-2 gap-6 max-w-5xl">

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold mb-1">🚚 Steadfast কুরিয়ার</p>
        <p class="text-xs text-mute mb-4">steadfast.com.bd → API সেকশন থেকে Key দুটো নিন। এটা দিলেই এক-ক্লিক কুরিয়ার + ফ্রড চেকার চালু হবে।</p>
        <form method="POST" action="{{ route('tenant.settings.courier') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="provider" value="steadfast">
            <input name="credentials[api_key]" placeholder="API Key {{ isset($couriers['steadfast']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="credentials[secret_key]" placeholder="Secret Key" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($couriers['steadfast']->is_active ?? false)> চালু
            </label>
            <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold mb-1">🚚 Pathao কুরিয়ার</p>
        <p class="text-xs/relaxed text-mute mb-4">Pathao Merchant প্যানেল → Developer API থেকে তথ্যগুলো নিন।</p>
        <form method="POST" action="{{ route('tenant.settings.courier') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="provider" value="pathao">
            <input name="credentials[client_id]" placeholder="Client ID" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="credentials[client_secret]" placeholder="Client Secret" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="credentials[username]" placeholder="Merchant Email" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="credentials[password]" type="password" placeholder="Merchant Password" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="credentials[store_id]" placeholder="Store ID" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($couriers['pathao']->is_active ?? false)> চালু
            </label>
            <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold mb-1">📩 Messenger ইনবক্স</p>
        <p class="text-xs text-mute mb-4">আপনার Facebook Page কানেক্ট করুন — মেসেঞ্জারের সব মেসেজ সরাসরি প্যানেলে চলে আসবে, এক ক্লিকে অর্ডারে রূপান্তর করা যাবে।</p>
        <form method="POST" action="{{ route('tenant.settings.messenger') }}" class="space-y-3">
            @csrf
            <input name="page_id" value="{{ $messenger->page_id ?? '' }}" required placeholder="Facebook Page ID"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="page_name" value="{{ $messenger->page_name ?? '' }}" placeholder="পেজের নাম (ঐচ্ছিক, শুধু চেনার জন্য)"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="page_access_token" type="password"
                   placeholder="Page Access Token {{ $messenger?->page_access_token ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($messenger->is_active ?? true)> চালু
            </label>
            <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
        </form>
        <p class="text-xs text-mute mt-3">Page ID ও Access Token পাবেন <a href="https://developers.facebook.com" target="_blank" class="text-leaf hover:underline">Meta for Developers</a> থেকে আপনার অ্যাপে Messenger প্রোডাক্ট যোগ করে।</p>
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6 lg:col-span-2">
        <p class="font-bold mb-1">📣 Facebook Pixel, Conversion API ও GTM</p>
        <p class="text-xs text-mute mb-4">Pixel ID দিলে ব্রাউজার ইভেন্ট যাবে। সাথে CAPI টোকেন দিলে সার্ভার থেকেও ইভেন্ট যাবে (iOS/অ্যাডব্লকারেও ট্র্যাকিং ঠিক থাকবে) — দুটোতেই একই event_id যায়, তাই ডাবল কাউন্ট হবে না। শুধু GTM ব্যবহার করলে শুধু GTM ID দিলেই চলবে।</p>
        <form method="POST" action="{{ route('tenant.settings.marketing') }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            <div>
                <label class="text-xs text-mute">Facebook Pixel ID</label>
                <input name="fb_pixel_id" value="{{ $marketing->fb_pixel_id }}" placeholder="1234567890"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="text-xs text-mute">GTM Container ID</label>
                <input name="gtm_container_id" value="{{ $marketing->gtm_container_id }}" placeholder="GTM-XXXXXXX"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="text-xs text-mute">Conversion API Access Token {{ $marketing->fb_capi_token ? '(সেভ করা আছে — বদলাতে চাইলে নতুন টোকেন দিন)' : '' }}</label>
                <input name="fb_capi_token" type="password" placeholder="EAAG..."
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="text-xs text-mute">Test Event Code (টেস্টের সময়)</label>
                <input name="fb_test_event_code" value="{{ $marketing->fb_test_event_code }}" placeholder="TEST12345"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="text-xs text-mute">Meta Ad Account ID</label>
                <input name="meta_ad_account_id" value="{{ $marketing->meta_ad_account_id }}" placeholder="act_123456"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <div class="md:col-span-2">
                <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold mb-1">🌐 কাস্টম ডোমেইন</p>
        @php $tenant = app('currentTenant'); @endphp
        @if (! $tenant->plan?->allow_custom_domain)
            <p class="text-xs text-mute mb-4">এই ফিচারটি শুধু Pro প্ল্যানে আছে। <a href="{{ route('tenant.billing') }}" class="text-leaf hover:underline">আপগ্রেড করুন</a>।</p>
        @elseif ($tenant->custom_domain_verified && $tenant->custom_domain)
            <p class="text-sm text-leafdk mt-2">✅ সক্রিয়: <b>{{ $tenant->custom_domain }}</b></p>
        @elseif ($tenant->custom_domain_request_status === 'pending')
            <p class="text-xs text-mute mb-2">আপনার রিকোয়েস্ট পর্যালোচনাধীন।</p>
            <p class="text-sm">⏳ <b>{{ $tenant->custom_domain_requested }}</b> — অ্যাডমিনের অনুমোদনের অপেক্ষায়</p>
        @else
            @if ($tenant->custom_domain_request_status === 'rejected')
                <p class="text-xs text-red-600 mb-3">আপনার আগের রিকোয়েস্টটি বাতিল হয়েছে — আবার চেষ্টা করুন বা অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
            @else
                <p class="text-xs text-mute mb-4">নিজের ডোমেইন (যেমন myshop.com) থাকলে এখানে দিন, অ্যাডমিন যাচাই করে চালু করে দেবে।</p>
            @endif
            <form method="POST" action="{{ route('tenant.settings.domain') }}" class="flex gap-2">
                @csrf
                <input name="custom_domain_requested" placeholder="myshop.com" required
                       class="flex-1 rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <button class="px-4 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">রিকোয়েস্ট পাঠান</button>
            </form>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold mb-4">🛵 ডেলিভারি চার্জ</p>
        <form method="POST" action="{{ route('tenant.settings.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="text-sm">ঢাকার ভেতরে</label>
                <input name="delivery_charge_inside_dhaka" type="number" min="0" required
                       value="{{ $store['delivery_charge_inside_dhaka'] ?? 60 }}"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="text-sm">ঢাকার বাইরে</label>
                <input name="delivery_charge_outside_dhaka" type="number" min="0" required
                       value="{{ $store['delivery_charge_outside_dhaka'] ?? 120 }}"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
        </form>
    </div>
</div>
@endsection
