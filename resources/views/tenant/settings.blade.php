@extends('layouts.panel')

@section('title', 'সেটিংস')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">সেটিংস</h1>

<a href="{{ route('tenant.notifications.preferences') }}" class="block mb-6 max-w-5xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 rounded-card">
    <x-ui.card hoverable padding="sm" class="flex items-center gap-3">
        <i data-lucide="bell" class="w-4 h-4 text-leafdk shrink-0"></i>
        <span class="flex-1 text-sm font-semibold">🔔 নোটিফিকেশন</span>
        <i data-lucide="chevron-right" class="w-4 h-4 text-mute shrink-0"></i>
    </x-ui.card>
</a>

<div class="grid lg:grid-cols-2 gap-6 max-w-5xl">

    <x-ui.collapsible-card title="🚚 Steadfast কুরিয়ার">
        <x-slot:status>
            @if ($couriers['steadfast']->is_active ?? false)<x-ui.badge tone="leaf">চালু</x-ui.badge>@endif
        </x-slot:status>
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="🚚 Pathao কুরিয়ার">
        <x-slot:status>
            @if ($couriers['pathao']->is_active ?? false)<x-ui.badge tone="leaf">চালু</x-ui.badge>@endif
        </x-slot:status>
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="🔗 Facebook কানেক্ট (Messenger)">
        <x-slot:status>
            @if ($facebookConnection && $facebookPages->where('is_active', true)->where('status', 'active')->isNotEmpty())
                <x-ui.badge tone="leaf">কানেক্টেড</x-ui.badge>
            @elseif ($facebookConnection)
                <x-ui.badge tone="amber">মনোযোগ প্রয়োজন</x-ui.badge>
            @endif
        </x-slot:status>
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="💬 WhatsApp কানেক্ট">
        <x-slot:status>
            @php $waActiveForBadge = $whatsappPhoneNumbers->where('is_active', true); @endphp
            @if ($waActiveForBadge->where('status', 'active')->isNotEmpty())
                <x-ui.badge tone="leaf">কানেক্টেড</x-ui.badge>
            @elseif ($whatsappAccount)
                <x-ui.badge tone="amber">মনোযোগ প্রয়োজন</x-ui.badge>
            @endif
        </x-slot:status>
        <p class="text-xs text-mute mb-4">Meta-এর অফিসিয়াল WhatsApp Business Platform (Embedded Signup) দিয়ে আপনার WhatsApp Business নম্বর কানেক্ট করুন — কোনো টোকেন কপি-পেস্ট করা লাগবে না।</p>

        @php
            $waActiveNumbers = $whatsappPhoneNumbers->where('is_active', true);
        @endphp

        @if (! $whatsappAccount && ! $whatsappFeatureEnabled)
            {{-- Not connected, and this plan doesn't include WhatsApp — same
                 "explain + link to upgrade" shape as the custom-domain card
                 below, rather than a Connect button that would just 403/
                 redirect back with an error via 'feature:whatsapp'. --}}
            <p class="text-xs text-mute mb-4">এই ফিচারটি আপনার বর্তমান প্ল্যানে অন্তর্ভুক্ত নেই। <a href="{{ route('tenant.billing') }}" class="text-leaf hover:underline">আপগ্রেড করুন</a>।</p>

        @elseif (! $whatsappAccount)
            {{-- Not Connected --}}
            <x-ui.button type="button" id="whatsappConnectBtn" variant="accent" size="sm"
                data-state="{{ $whatsappConnectState->state ?? '' }}"
                data-app-id="{{ config('facebook.app_id') }}"
                data-config-id="{{ config('whatsapp.embedded_signup_config_id') }}"
                data-graph-version="{{ config('whatsapp.graph_version') }}"
                data-complete-url="{{ route('tenant.whatsapp.connect.complete') }}">
                Connect WhatsApp
            </x-ui.button>

        @elseif ($waActiveNumbers->isEmpty())
            {{-- WABA connected but no active number (disconnected earlier, or the previous attempt never finished) --}}
            <p class="text-sm text-leafdk mb-3">✅ WhatsApp Business Account কানেক্ট করা হয়েছে — এখন একটি নম্বর কানেক্ট করুন।</p>
            @if ($whatsappFeatureEnabled)
                <x-ui.button type="button" id="whatsappConnectBtn" variant="accent" size="sm"
                    data-state="{{ $whatsappConnectState->state ?? '' }}"
                    data-app-id="{{ config('facebook.app_id') }}"
                    data-config-id="{{ config('whatsapp.embedded_signup_config_id') }}"
                    data-graph-version="{{ config('whatsapp.graph_version') }}"
                    data-complete-url="{{ route('tenant.whatsapp.connect.complete') }}">
                    Connect Number
                </x-ui.button>
            @else
                <p class="text-xs text-mute">এই ফিচারটি আপনার বর্তমান প্ল্যানে অন্তর্ভুক্ত নেই। <a href="{{ route('tenant.billing') }}" class="text-leaf hover:underline">আপগ্রেড করুন</a>।</p>
            @endif

        @else
            @unless ($whatsappFeatureEnabled)
                <p class="text-xs text-amber-700 mb-3">⚠️ আপনার বর্তমান প্ল্যানে এই ফিচারটি আর অন্তর্ভুক্ত নেই — বিদ্যমান সংযোগ দেখা ও ডিসকানেক্ট করা যাবে, কিন্তু পুনরায় কানেক্ট করতে <a href="{{ route('tenant.billing') }}" class="text-leaf hover:underline">আপগ্রেড</a> প্রয়োজন।</p>
            @endunless
            <div class="space-y-3 mb-3">
                @foreach ($waActiveNumbers as $waPhone)
                    <div class="border border-ink/10 rounded-btn p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-semibold text-sm">{{ $waPhone->display_phone_number ?? $waPhone->verified_name ?? 'নামহীন নম্বর' }}</p>
                                <p class="text-xs text-mute">Phone Number ID: {{ $waPhone->phone_number_id }}</p>
                                @if ($whatsappAccount->business_name)
                                    <p class="text-xs text-mute">Business: {{ $whatsappAccount->business_name }}</p>
                                @endif
                            </div>
                            @if ($waPhone->status === 'active')
                                <x-ui.badge tone="leaf">✅ সক্রিয়, সাবস্ক্রাইবড</x-ui.badge>
                            @elseif ($waPhone->status === 'subscription_failed')
                                <x-ui.badge tone="amber">⚠️ সাবস্ক্রিপশন ব্যর্থ</x-ui.badge>
                            @else
                                <x-ui.badge tone="amber">🔄 পুনরায় কানেক্ট প্রয়োজন</x-ui.badge>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2 mt-3">
                            @if ($waPhone->status !== 'active' && $whatsappFeatureEnabled)
                                <x-ui.button type="button" class="whatsapp-connect-btn" variant="accent" size="sm"
                                    data-state="{{ $whatsappConnectState->state ?? '' }}"
                                    data-app-id="{{ config('facebook.app_id') }}"
                                    data-config-id="{{ config('whatsapp.embedded_signup_config_id') }}"
                                    data-graph-version="{{ config('whatsapp.graph_version') }}"
                                    data-complete-url="{{ route('tenant.whatsapp.connect.complete') }}">
                                    Reconnect WhatsApp
                                </x-ui.button>
                            @endif
                            <form method="POST" action="{{ route('tenant.whatsapp.disconnect', $waPhone) }}" onsubmit="return confirm('এই WhatsApp নম্বর ডিসকানেক্ট করতে চান?');">
                                @csrf
                                <x-ui.button type="submit" variant="outline" size="sm">Disconnect</x-ui.button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="🛠️ উন্নত: ম্যানুয়াল Page Access Token">
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="📣 Facebook Pixel, Conversion API ও GTM" class="lg:col-span-2">
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="🌐 কাস্টম ডোমেইন">
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
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm">⏳ <b>{{ $tenant->custom_domain_requested }}</b> — DNS যাচাইয়ের অপেক্ষায়</p>
                <form method="POST" action="{{ route('tenant.settings.domain.cancel') }}" onsubmit="return confirm('রিকোয়েস্টটি বাতিল করবেন? ভুল ডোমেইন দিলে বাতিল করে আবার সঠিকটি দিতে পারবেন।')">
                    @csrf @method('DELETE')
                    <button class="text-red-600 text-xs hover:underline rounded shrink-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">বাতিল / পরিবর্তন করুন</button>
                </form>
            </div>
        @elseif ($tenant->custom_domain_request_status === 'dns_verified')
            <p class="text-xs text-leafdk mb-2">✅ DNS যাচাই সম্পন্ন হয়েছে।</p>
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm">⏳ <b>{{ $tenant->custom_domain_requested }}</b> — আমাদের টিম সেটআপ শেষ করলেই চালু হয়ে যাবে</p>
                <form method="POST" action="{{ route('tenant.settings.domain.cancel') }}" onsubmit="return confirm('রিকোয়েস্টটি বাতিল করবেন? ভুল ডোমেইন দিলে বাতিল করে আবার সঠিকটি দিতে পারবেন।')">
                    @csrf @method('DELETE')
                    <button class="text-red-600 text-xs hover:underline rounded shrink-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">বাতিল / পরিবর্তন করুন</button>
                </form>
            </div>
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="🛵 ডেলিভারি চার্জ" :open="true">
        <p class="text-xs text-mute -mt-2 mb-1">অর্ডার তৈরির সময় বিভাগ অনুযায়ী এই চার্জ অটো বসে যাবে (ঢাকা বিভাগ = ভেতরে, বাকি সব = বাইরে)।</p>
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
    </x-ui.collapsible-card>

    <x-ui.collapsible-card title="🤖 AI কাস্টমার এজেন্ট" :open="true">
        <x-slot:status>
            @if (($store['ai_agent_enabled'] ?? '0') === '1')<x-ui.badge tone="leaf">চালু</x-ui.badge>@endif
        </x-slot:status>

        {{-- Read-only — balance itself is only ever changed by Super Admin. --}}
        <div class="flex items-center justify-between rounded-lg border border-ink/10 bg-paper/60 px-3 py-2.5 mb-3 text-sm">
            <span class="text-mute">AI ক্রেডিট ব্যালেন্স</span>
            @if (is_null($aiCreditBalance))
                <span class="font-semibold text-mute">বরাদ্দ করা হয়নি</span>
            @elseif ((float) $aiCreditBalance <= 0)
                <span class="font-semibold text-red-600">০ (শেষ হয়ে গেছে)</span>
            @else
                <span class="font-semibold text-leafdk">{{ number_format((float) $aiCreditBalance, 2) }}</span>
            @endif
        </div>
        @if (is_null($aiCreditBalance) || (float) $aiCreditBalance <= 0)
            <p class="text-xs text-red-600 -mt-1 mb-3">ক্রেডিট শেষ হয়ে গেলে (অথবা কখনো বরাদ্দ না হলে) AI চালু থাকলেও কোনো রিপ্লাই পাঠাবে না, যতক্ষণ না সুপার অ্যাডমিন নতুন ক্রেডিট যোগ করেন। আপনার সেটিংস/কনফিগারেশন অপরিবর্তিত থাকে।</p>
        @endif

        <p class="text-xs text-mute mb-1">"AI এজেন্ট" মাস্টার সুইচ — বন্ধ থাকলে কোনো চ্যানেলেই AI কোনো OpenAI কল করবে না। "Messenger অটো রিপ্লাই" এবং "WhatsApp অটো রিপ্লাই" প্রতিটি চ্যানেলের জন্য আলাদা সুইচ — মাস্টার সুইচ এবং সংশ্লিষ্ট চ্যানেলের সুইচ দুটোই চালু থাকলে তবেই সেই চ্যানেলে স্বয়ংক্রিয় রিপ্লাই যাবে।</p>
        <form method="POST" action="{{ route('tenant.settings.ai-agent') }}">
            @csrf
            <label class="flex items-center gap-2 text-sm mb-2">
                <input type="checkbox" name="ai_agent_enabled" value="1" @checked(($store['ai_agent_enabled'] ?? '0') === '1')> AI এজেন্ট চালু <span class="text-mute">(মাস্টার সুইচ)</span>
            </label>
            <label class="flex items-center gap-2 text-sm mb-2">
                <input type="checkbox" name="messenger_ai_auto_reply_enabled" value="1" @checked(($store['messenger_ai_auto_reply_enabled'] ?? '0') === '1')> Messenger অটো রিপ্লাই চালু
            </label>
            <label class="flex items-center gap-2 text-sm mb-3">
                <input type="checkbox" name="whatsapp_ai_auto_reply_enabled" value="1" @checked(($store['whatsapp_ai_auto_reply_enabled'] ?? '0') === '1')> WhatsApp অটো রিপ্লাই চালু
            </label>

            <div class="border-t border-ink/10 pt-3 mb-3">
                <label class="text-sm font-semibold block mb-1">AI-কে আপনার ব্যবসার বিষয়ে কী কী জানা দরকার?</label>
                <p class="text-xs text-mute mb-2">যেমন — ব্যবহারের ধরন, delivery charge, পেমেন্ট মেথড, discount policy, কাস্টমারের সাথে কথা বলার ধরন, কোন জিনিস কখনো বলা যাবে না — যা লিখবেন AI সবসময় সেটা মাথায় রেখে রিপ্লাই দেবে। তবে এটা AI-এর মূল নিরাপত্তা নিয়ম override করতে পারবে না (যেমন — না জানা দাম বানিয়ে বলা)।</p>
                <textarea name="ai_custom_instructions" rows="5" maxlength="2000"
                    placeholder="উদাহরণ:&#10;আমাদের কাস্টমারদের সাথে বন্ধুসুলভভাবে কথা বলবে।&#10;ঢাকার ভিতরে delivery charge ৮০ টাকা, বাইরে ১৫০ টাকা।&#10;ক্যাশ অন ডেলিভারি আছে।&#10;কোনো discount নিজে থেকে দিবে না।&#10;কাস্টমার রাগ করলে argument করবে না।"
                    class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">{{ $store['ai_custom_instructions'] ?? '' }}</textarea>
                <p class="text-xs text-mute mt-1 text-right">সর্বোচ্চ ২০০০ অক্ষর</p>
            </div>

            <x-ui.button type="submit" variant="accent" size="sm">সেভ করুন</x-ui.button>
        </form>
    </x-ui.collapsible-card>
</div>

@push('scripts')
<script>
(function () {
    // Only load Meta's JS SDK / wire up Embedded Signup at all if a Connect/
    // Reconnect WhatsApp button is actually on this page render — a tenant
    // with a fully active connection never gets one (see SettingController::
    // index()'s $whatsappConnectState), so this whole block is a no-op for
    // them rather than an unconditional third-party script load.
    const connectButtons = document.querySelectorAll('#whatsappConnectBtn, .whatsapp-connect-btn');
    if (!connectButtons.length) return;

    const config = connectButtons[0].dataset;
    if (!config.appId || !config.configId) return; // not configured yet — nothing to wire up

    let popupResult = null; // {code} from FB.login's callback
    // {wabaId, phoneNumberId, businessId} from the postMessage event.
    // phoneNumberId is null for a WhatsApp Business App Coexistence
    // completion (FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING) — Meta's
    // documented payload for that event carries only waba_id, since the
    // whole point of that flow is "skip phone number registration, it's
    // already registered." WhatsAppConnectController::complete() discovers
    // the phone number server-side via a fresh Graph API call in that case
    // — never invented or guessed here.
    let signupResult = null;

    function trySubmit() {
        if (!popupResult || !signupResult) return;

        // A real <form> POST (not fetch) — WhatsAppConnectController::
        // complete() is a normal panel action that redirects back to
        // Settings with a flash message, same as every other action here.
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = config.completeUrl;
        form.style.display = 'none';

        const fields = {
            _token: document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value,
            state: config.state,
            code: popupResult.code,
            waba_id: signupResult.wabaId,
            phone_number_id: signupResult.phoneNumberId,
            business_id: signupResult.businessId || '',
        };

        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value || '';
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== 'https://www.facebook.com') return;

        let data;
        try {
            data = JSON.parse(event.data);
        } catch (e) {
            return; // not a JSON message Meta sent us — ignore
        }

        if (data.type !== 'WA_EMBEDDED_SIGNUP') return;

        // FINISH / FINISH_ONLY_WABA: standard (non-coexistence) Embedded
        // Signup — Meta's documented payload for these carries phone_number_id
        // directly (absent/undefined for FINISH_ONLY_WABA, since no number was
        // set up in that case — same as before this change).
        //
        // FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING: the WhatsApp Business App
        // Coexistence completion event — Meta's documented payload for this
        // one is { data: { waba_id } } only, no phone_number_id, because the
        // flow's entire purpose is onboarding a number that's already
        // registered with the WhatsApp Business App (registration is skipped).
        // Never invented/guessed here — phoneNumberId stays null and the
        // backend discovers it via a fresh Graph API call scoped to this
        // waba_id (see WhatsAppConnectController::complete()).
        if (
            data.event === 'FINISH'
            || data.event === 'FINISH_ONLY_WABA'
            || data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'
        ) {
            signupResult = {
                wabaId: data.data?.waba_id,
                phoneNumberId: data.data?.phone_number_id ?? null,
                businessId: data.data?.business_id,
            };
            trySubmit();
        }
    });

    window.fbAsyncInit = function () {
        FB.init({ appId: config.appId, autoLogAppEvents: true, xfbml: true, version: config.graphVersion });
    };

    (function loadSdk() {
        if (document.getElementById('facebook-jssdk')) return;
        const script = document.createElement('script');
        script.id = 'facebook-jssdk';
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.defer = true;
        document.body.appendChild(script);
    })();

    connectButtons.forEach((btn) => btn.addEventListener('click', function () {
        if (typeof FB === 'undefined') return; // SDK still loading — tenant can just click again in a moment

        FB.login(function (response) {
            if (response.authResponse && response.authResponse.code) {
                popupResult = { code: response.authResponse.code };
                trySubmit();
            }
        }, {
            config_id: btn.dataset.configId,
            response_type: 'code',
            override_default_response_type: true,
            // featureType: 'whatsapp_business_app_onboarding' is Meta's
            // documented value for enabling WhatsApp Business App
            // Coexistence within Embedded Signup — a tenant already using
            // the WhatsApp Business App can connect that same number
            // without migrating off it. sessionInfoVersion: '3' was already
            // correct (required to receive waba_id in the postMessage
            // event) and is unchanged. This does not remove the standard
            // new-number flow — both FINISH (standard) and
            // FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING (coexistence) are
            // handled by the message listener above; whichever path the
            // tenant actually takes inside the popup, this app's code
            // handles the resulting event correctly.
            extras: { setup: {}, featureType: 'whatsapp_business_app_onboarding', sessionInfoVersion: '3' },
        });
    }));
})();
</script>
@endpush
@endsection
