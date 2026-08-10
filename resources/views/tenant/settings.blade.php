@extends('layouts.panel')

@section('title', 'সেটিংস')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">সেটিংস</h1>

<div class="grid lg:grid-cols-2 gap-6 max-w-5xl">

    <x-ui.card>
        <p class="font-bold mb-1">🚚 Steadfast কুরিয়ার</p>
        <p class="text-xs text-mute mb-4">steadfast.com.bd → API সেকশন থেকে Key দুটো নিন। এটা দিলেই এক-ক্লিক কুরিয়ার + ফ্রড চেকার চালু হবে।</p>
        <form method="POST" action="{{ route('tenant.settings.courier') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="provider" value="steadfast">
            <input name="credentials[api_key]" placeholder="API Key {{ isset($couriers['steadfast']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="credentials[secret_key]" placeholder="Secret Key {{ ! empty($couriers['steadfast']->credentials['secret_key'] ?? null) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($couriers['steadfast']->is_active ?? false)> চালু
            </label>
            <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-1">🚚 Pathao কুরিয়ার</p>
        <p class="text-xs/relaxed text-mute mb-4">Pathao Merchant প্যানেল → Developer API থেকে তথ্যগুলো নিন।</p>
        <form method="POST" action="{{ route('tenant.settings.courier') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="provider" value="pathao">
            @php $pathaoCreds = $couriers['pathao']->credentials ?? []; @endphp
            <input name="credentials[client_id]" placeholder="Client ID {{ ! empty($pathaoCreds['client_id']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="credentials[client_secret]" placeholder="Client Secret {{ ! empty($pathaoCreds['client_secret']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="credentials[username]" placeholder="Merchant Email {{ ! empty($pathaoCreds['username']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="credentials[password]" type="password" placeholder="Merchant Password {{ ! empty($pathaoCreds['password']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="credentials[store_id]" placeholder="Store ID {{ ! empty($pathaoCreds['store_id']) ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($couriers['pathao']->is_active ?? false)> চালু
            </label>
            <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-1">🔗 Facebook কানেক্ট (Messenger)</p>
        <p class="text-xs text-mute mb-4">Facebook দিয়ে লগইন করে সরাসরি আপনার Page কানেক্ট করুন — কোনো টোকেন কপি-পেস্ট করা লাগবে না, ওয়েবহুক সাবস্ক্রিপশনও স্বয়ংক্রিয়ভাবে হয়ে যাবে।</p>

        @if (! $facebookConnection)
            {{-- Not Connected --}}
            <x-ui.button href="{{ route('tenant.facebook.connect') }}" variant="accent" size="sm">Connect Facebook</x-ui.button>

        @elseif ($facebookPages->where('is_active', true)->isEmpty())
            {{-- Facebook Connected / No Page Selected --}}
            <p class="text-sm text-leafdk mb-3">✅ Facebook কানেক্ট করা হয়েছে — এখন একটি Page বাছাই করুন।</p>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('tenant.facebook.pages') }}" variant="accent" size="sm">Page বাছাই করুন</x-ui.button>
                <x-ui.button href="{{ route('tenant.facebook.connect') }}" variant="outline" size="sm">Reconnect Facebook</x-ui.button>
            </div>

        @else
            {{-- Page Connected / Subscription Failed / Reconnect Required --}}
            <div class="space-y-3 mb-3">
                @foreach ($facebookPages->where('is_active', true) as $fbPage)
                    <div class="border border-ink/10 rounded-btn p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-sm">{{ $fbPage->page_name ?? 'নামহীন Page' }}</p>
                                <p class="text-xs text-mute">Page ID: {{ $fbPage->page_id }}</p>
                            </div>
                            @if ($fbPage->status === 'active')
                                <x-ui.badge tone="leaf">✅ সক্রিয়, সাবস্ক্রাইবড</x-ui.badge>
                            @elseif ($fbPage->status === 'subscription_failed')
                                <x-ui.badge tone="amber">⚠️ সাবস্ক্রিপশন ব্যর্থ</x-ui.badge>
                            @else
                                <x-ui.badge tone="amber">🔄 পুনরায় কানেক্ট প্রয়োজন</x-ui.badge>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2 mt-3">
                            @if ($fbPage->status === 'needs_reconnect')
                                <x-ui.button href="{{ route('tenant.facebook.connect') }}" variant="accent" size="sm">Reconnect Facebook</x-ui.button>
                            @elseif ($fbPage->status === 'subscription_failed')
                                <x-ui.button href="{{ route('tenant.facebook.pages') }}" variant="accent" size="sm">আবার চেষ্টা করুন</x-ui.button>
                            @endif
                            <form method="POST" action="{{ route('tenant.facebook.pages.disconnect', $fbPage) }}" onsubmit="return confirm('এই Page ডিসকানেক্ট করতে চান?');">
                                @csrf
                                <x-ui.button type="submit" variant="outline" size="sm">Disconnect</x-ui.button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
            <x-ui.button href="{{ route('tenant.facebook.pages') }}" variant="outline" size="sm">আরেকটি Page যোগ করুন</x-ui.button>
        @endif
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-1">🛠️ উন্নত: ম্যানুয়াল Page Access Token</p>
        <p class="text-xs text-mute mb-4">উপরের <b>Connect Facebook</b> ব্যবহার করাই সহজ ও সুপারিশকৃত। শুধু প্রয়োজন হলে (যেমন OAuth কাজ না করলে) নিচে ম্যানুয়ালি Page ID ও Access Token দিন।</p>
        <form method="POST" action="{{ route('tenant.settings.messenger') }}" class="space-y-3">
            @csrf
            <input name="page_id" value="{{ $messenger->page_id ?? '' }}" required placeholder="Facebook Page ID"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="page_name" value="{{ $messenger->page_name ?? '' }}" placeholder="পেজের নাম (ঐচ্ছিক, শুধু চেনার জন্য)"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <input name="page_access_token" type="password"
                   placeholder="Page Access Token {{ $messenger?->page_access_token ? '(সেভ করা আছে — বদলাতে চাইলে লিখুন)' : '' }}"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($messenger->is_active ?? true)> চালু
            </label>
            <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
        </form>
        <p class="text-xs text-mute mt-3">Page ID ও Access Token পাবেন <a href="https://developers.facebook.com" target="_blank" class="text-leaf hover:underline">Meta for Developers</a> থেকে আপনার অ্যাপে Messenger প্রোডাক্ট যোগ করে।</p>
    </x-ui.card>

    <x-ui.card class="lg:col-span-2">
        <p class="font-bold mb-1">📣 Facebook Pixel, Conversion API ও GTM</p>
        <p class="text-xs text-mute mb-4">Pixel ID দিলে ব্রাউজার ইভেন্ট যাবে। সাথে CAPI টোকেন দিলে সার্ভার থেকেও ইভেন্ট যাবে (iOS/অ্যাডব্লকারেও ট্র্যাকিং ঠিক থাকবে) — দুটোতেই একই event_id যায়, তাই ডাবল কাউন্ট হবে না। শুধু GTM ব্যবহার করলে শুধু GTM ID দিলেই চলবে।</p>
        <form method="POST" action="{{ route('tenant.settings.marketing') }}" class="grid md:grid-cols-2 gap-3">
            @csrf
            <div>
                <label class="text-xs text-mute">Facebook Pixel ID</label>
                <input name="fb_pixel_id" value="{{ $marketing->fb_pixel_id }}" placeholder="1234567890"
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div>
                <label class="text-xs text-mute">GTM Container ID</label>
                <input name="gtm_container_id" value="{{ $marketing->gtm_container_id }}" placeholder="GTM-XXXXXXX"
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div class="md:col-span-2">
                <label class="text-xs text-mute">Conversion API Access Token {{ $marketing->fb_capi_token ? '(সেভ করা আছে — বদলাতে চাইলে নতুন টোকেন দিন)' : '' }}</label>
                <input name="fb_capi_token" type="password" placeholder="EAAG..."
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div>
                <label class="text-xs text-mute">Test Event Code (টেস্টের সময়)</label>
                <input name="fb_test_event_code" value="{{ $marketing->fb_test_event_code }}" placeholder="TEST12345"
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div>
                <label class="text-xs text-mute">Meta Ad Account ID</label>
                <input name="meta_ad_account_id" value="{{ $marketing->meta_ad_account_id }}" placeholder="act_123456"
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div class="md:col-span-2">
                <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-1">🌐 কাস্টম ডোমেইন</p>
        @php $tenant = app('currentTenant'); @endphp
        @if (! $tenant->plan?->allow_custom_domain)
            <p class="text-xs text-mute mb-4">এই ফিচারটি শুধু Pro প্ল্যানে আছে। <a href="{{ route('tenant.billing') }}" class="text-leaf hover:underline">আপগ্রেড করুন</a>।</p>
        @elseif ($tenant->custom_domain_verified && $tenant->custom_domain)
            <p class="text-sm text-leafdk mt-2">✅ সক্রিয়: <b>{{ $tenant->custom_domain }}</b></p>
        @elseif ($tenant->custom_domain_request_status === 'pending')
            <p class="text-xs text-mute mb-3">DNS-এ নিচের TXT রেকর্ডটি যোগ করুন — যোগ করার পর আমাদের টিম যাচাই করে পরের ধাপে নিয়ে যাবে।</p>
            <div class="bg-paper rounded-btn p-3 text-xs font-mono mb-3 space-y-1 overflow-x-auto">
                <p><span class="text-mute">Type:</span> TXT</p>
                <p><span class="text-mute">Host/Name:</span> @ ({{ $tenant->custom_domain_requested }})</p>
                <p><span class="text-mute">Value:</span> {{ $domainTxtValue }}</p>
            </div>
            <p class="text-sm">⏳ <b>{{ $tenant->custom_domain_requested }}</b> — DNS যাচাইয়ের অপেক্ষায়</p>
        @elseif ($tenant->custom_domain_request_status === 'dns_verified')
            <p class="text-xs text-leafdk mb-2">✅ DNS যাচাই সম্পন্ন হয়েছে।</p>
            <p class="text-sm">⏳ <b>{{ $tenant->custom_domain_requested }}</b> — আমাদের টিম সেটআপ শেষ করলেই চালু হয়ে যাবে</p>
        @else
            @if ($tenant->custom_domain_request_status === 'rejected')
                <p class="text-xs text-red-600 mb-3">আপনার আগের রিকোয়েস্টটি বাতিল হয়েছে — আবার চেষ্টা করুন বা অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
            @else
                <p class="text-xs text-mute mb-4">নিজের ডোমেইন (যেমন myshop.com) থাকলে এখানে দিন, অ্যাডমিন যাচাই করে চালু করে দেবে।</p>
            @endif
            <form method="POST" action="{{ route('tenant.settings.domain') }}" class="flex gap-2">
                @csrf
                <input name="custom_domain_requested" placeholder="myshop.com" required
                       class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
                <x-ui.button type="submit" variant="accent" size="sm">রিকোয়েস্ট পাঠান</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-4">🛵 ডেলিভারি চার্জ</p>
        <form method="POST" action="{{ route('tenant.settings.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="text-sm">ঢাকার ভেতরে</label>
                <input name="delivery_charge_inside_dhaka" type="number" min="0" required
                       value="{{ $store['delivery_charge_inside_dhaka'] ?? 60 }}"
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div>
                <label class="text-sm">ঢাকার বাইরে</label>
                <input name="delivery_charge_outside_dhaka" type="number" min="0" required
                       value="{{ $store['delivery_charge_outside_dhaka'] ?? 120 }}"
                       class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
        </form>
    </x-ui.card>
</div>
@endsection
