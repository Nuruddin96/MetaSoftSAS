@php
    $set        = \App\Models\StoreSetting::pluck('value', 'key');
    $headerPages = \App\Models\Page::where('is_active', 1)->where('show_in_header', 1)->orderBy('sort_order')->get();
    $footerPages = \App\Models\Page::where('is_active', 1)->where('show_in_footer', 1)->orderBy('sort_order')->get();
    $mk         = \App\Models\MarketingSetting::first();
    $cartCount  = collect(session('cart_' . $tenant->id, []))->sum();
@endphp
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $tenant->store_name)</title>
    <meta name="description" content="@yield('meta_description', $set['footer_about'] ?? $tenant->store_name)">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:title" content="@yield('title', $tenant->store_name)">
    <meta property="og:description" content="@yield('meta_description', $set['footer_about'] ?? $tenant->store_name)">
    <meta property="og:type" content="website">
    @if ($tenant->logo_path)<meta property="og:image" content="{{ asset('storage/' . $tenant->logo_path) }}">@endif
    @if ($tenant->logo_path)<link rel="icon" href="{{ asset('storage/' . $tenant->logo_path) }}">@endif
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Storefront paper is a slightly different shade, and brand/accent come from this tenant's own colors. --}}
    <style>
        :root {
            --color-paper: #F7F6F1;
            --color-brand: {{ $tenant->primary_color ?: '#128155' }};
            --color-accent: {{ $tenant->secondary_color ?: '#f59e0b' }};
        }
    </style>

    @if ($mk?->gtm_container_id)
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
    var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;
    j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','{{ $mk->gtm_container_id }}');</script>
    @endif

    @if ($mk?->fb_pixel_id)
    <script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '{{ $mk->fb_pixel_id }}');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id={{ $mk->fb_pixel_id }}&ev=PageView&noscript=1"/></noscript>
    @endif
</head>
<body class="font-body bg-paper text-ink antialiased">
@if ($mk?->gtm_container_id)
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $mk->gtm_container_id }}"
    height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif

@if (! empty($set['announcement']))
    <div class="bg-brand text-white text-center text-sm py-2 px-4">{{ $set['announcement'] }}</div>
@endif

<header class="bg-white border-b border-ink/5 sticky top-0 z-40">
    <div class="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between gap-4">
        <a href="{{ route('storefront.home') }}" class="flex items-center gap-2 min-w-0">
            @if ($tenant->logo_path)
                <img src="{{ asset('storage/' . $tenant->logo_path) }}" alt="{{ $tenant->store_name }}" class="h-10 max-w-[160px] object-contain"
                     onerror="this.style.display='none'; this.nextElementSibling?.classList.remove('hidden');">
                <span class="hidden font-disp font-bold text-lg text-ink truncate">{{ $tenant->store_name }}</span>
            @else
                <span class="font-disp font-bold text-lg text-ink truncate">{{ $tenant->store_name }}</span>
            @endif
        </a>

        <nav class="flex items-center gap-4 md:gap-5 text-sm shrink-0">
            <a href="{{ route('storefront.products') }}" class="hidden sm:inline hover:text-brand">সব প্রোডাক্ট</a>
            @foreach ($headerPages as $p)
                <a href="{{ route('storefront.page', $p->slug) }}" class="hidden md:inline hover:text-brand">{{ $p->title }}</a>
            @endforeach
            <a href="{{ route('storefront.cart') }}" class="relative hover:text-brand">
                🛒 <span class="hidden sm:inline">কার্ট</span>
                @if ($cartCount)
                    <span class="absolute -top-2 -right-3 bg-brand text-white text-[10px] font-bold rounded-full w-5 h-5 grid place-items-center">{{ $cartCount }}</span>
                @endif
            </a>
        </nav>
    </div>
</header>

@if (session('success'))
    <div class="max-w-5xl mx-auto px-4 pt-4">
        <p class="bg-brand/10 border border-brand/30 rounded-lg px-4 py-3 text-sm">{{ session('success') }}</p>
    </div>
@endif

<main class="max-w-5xl mx-auto px-4 py-8 min-h-[55vh]">
    @yield('content')
</main>

<footer class="bg-brand text-white/90 mt-10">
    <div class="max-w-5xl mx-auto px-4 py-10 grid md:grid-cols-3 gap-8 text-sm">
        <div>
            @if ($tenant->logo_path)
                <img src="{{ asset('storage/' . $tenant->logo_path) }}" class="h-10 object-contain mb-3 bg-white/95 rounded px-2 py-1">
            @else
                <p class="font-disp font-bold text-lg text-white mb-2">{{ $tenant->store_name }}</p>
            @endif
            @if (! empty($set['footer_about']))
                <p class="text-white/75 leading-relaxed">{{ $set['footer_about'] }}</p>
            @endif

            @php $socials = array_filter([
                'facebook'  => $set['social_facebook'] ?? null,
                'instagram' => $set['social_instagram'] ?? null,
                'youtube'   => $set['social_youtube'] ?? null,
                'tiktok'    => $set['social_tiktok'] ?? null,
            ]); @endphp
            @if ($socials)
                <div class="flex gap-2.5 mt-4">
                    @foreach ($socials as $platform => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener"
                           class="w-9 h-9 rounded-full bg-white/10 grid place-items-center text-white/80 hover:bg-white hover:text-brand transition"
                           aria-label="{{ ucfirst($platform) }}">
                            @include('partials.icon', ['platform' => $platform, 'class' => 'w-4.5 h-4.5'])
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div>
            <p class="font-semibold mb-3 text-white">তথ্য</p>
            <a href="{{ route('storefront.products') }}" class="block text-white/75 hover:text-white py-0.5">সব প্রোডাক্ট</a>
            @foreach ($footerPages as $p)
                <a href="{{ route('storefront.page', $p->slug) }}" class="block text-white/75 hover:text-white py-0.5">{{ $p->title }}</a>
            @endforeach
        </div>

        <div>
            <p class="font-semibold mb-3 text-white">যোগাযোগ</p>
            @if (! empty($set['footer_phone']))
                <p class="text-white/75 py-0.5">📞 <a href="tel:{{ $set['footer_phone'] }}" class="hover:text-white">{{ $set['footer_phone'] }}</a></p>
            @endif
            @if (! empty($set['footer_email']))
                <p class="text-white/75 py-0.5">✉️ {{ $set['footer_email'] }}</p>
            @endif
            @if (! empty($set['footer_address']))
                <p class="text-white/75 py-0.5">📍 {{ $set['footer_address'] }}</p>
            @endif
        </div>
    </div>

    @if (! empty($set['footer_note']))
        <p class="text-center text-xs text-white/70 pb-4">{{ $set['footer_note'] }}</p>
    @endif

    <div class="border-t border-white/15 py-4 text-center text-xs text-white/70">
        © {{ date('Y') }} {{ $tenant->store_name }} — Powered by
        <a href="https://metasoftbd.com" class="hover:underline font-medium text-white/90">MetaSoft BD</a>
    </div>
</footer>

@if (($set['show_whatsapp_float'] ?? '0') === '1' && ! empty($set['whatsapp_number']))
    <a href="https://wa.me/{{ preg_replace('/\D/', '', $set['whatsapp_number']) }}" target="_blank" rel="noopener"
       class="fixed bottom-5 right-5 z-50 w-14 h-14 rounded-full bg-[#25D366] text-white grid place-items-center shadow-lg hover:scale-105 transition"
       aria-label="WhatsApp-এ মেসেজ করুন">
        <svg viewBox="0 0 24 24" fill="currentColor" class="w-7 h-7"><path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15s-.77.96-.94 1.16c-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37s-1.04 1.02-1.04 2.48 1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.04 21.5h-.01c-1.75 0-3.47-.47-4.97-1.36l-.36-.21-3.7.97.99-3.61-.23-.37a9.86 9.86 0 01-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 012.89 6.99c0 5.45-4.44 9.89-9.89 9.89M20.52 3.45A11.76 11.76 0 0012.04 0C5.5 0 .17 5.33.17 11.88c0 2.09.55 4.13 1.59 5.93L.07 24l6.33-1.66a11.85 11.85 0 005.64 1.44h.01c6.54 0 11.87-5.33 11.87-11.88 0-3.17-1.24-6.15-3.48-8.4"/></svg>
    </a>
@endif

@stack('scripts')
</body>
</html>
