<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'প্যানেল') — {{ app('currentTenant')->store_name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- PWA: manifest, icons, theme color. viewport-fit=cover above +
         env(safe-area-inset-*) in this layout's CSS is what actually lets
         content extend under a notch/gesture bar safely — this block only
         declares app identity/icons. --}}
    <link rel="manifest" href="{{ route('tenant.pwa.manifest') }}">
    <meta name="theme-color" content="#128155">
    <meta name="background-color" content="#F4F2EA">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icons/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/icons/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/icons/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="MetaSoft">
</head>
<body class="font-body bg-paper text-ink antialiased">
<x-ui.splash />
<div id="navProgress"></div>
@php
    $notifTenant = app('currentTenant');
    $adService = app(\App\Services\Advertising\AdvertisingBalanceService::class);
    $adEnabled = $adService->isEnabled($notifTenant);
    $adBalance = $adEnabled ? $adService->balance($notifTenant->id) : null;

    // Notifications have no persisted record of their own to hang a
    // read/unread flag on — badges below are live business-state counts.
    // "Read" is tracked per-category as a session timestamp instead (set
    // by NotificationController::markSeen() when the bell is opened, see
    // its docblock): once seen, only items newer than that mark still
    // count as unread. A category never seen this session shows its full
    // current count, same as before this existed.
    $notifSeen = [
        'pending_orders' => session('notif_seen.pending_orders'),
        'low_stock' => session('notif_seen.low_stock'),
        'messages' => session('notif_seen.messages'),
        'incomplete' => session('notif_seen.incomplete'),
    ];

    $notifPendingOrders = \App\Models\Order::where('status', 'pending')
        ->when($notifSeen['pending_orders'], fn ($q) => $q->where('created_at', '>', $notifSeen['pending_orders']))
        ->count();
    $notifLowStock = (int) \Illuminate\Support\Facades\DB::table('product_variants as pv')
        ->leftJoin('inventory as i', 'i.variant_id', '=', 'pv.id')
        ->where('pv.tenant_id', $notifTenant->id)
        ->select('pv.id')
        ->groupBy('pv.id', 'pv.low_stock_threshold')
        ->havingRaw('COALESCE(SUM(i.quantity), 0) <= pv.low_stock_threshold')
        ->when($notifSeen['low_stock'], fn ($q) => $q->havingRaw('MAX(i.updated_at) > ?', [$notifSeen['low_stock']]))
        ->get()->count();
    $notifNewMessages = \App\Models\MessengerMessage::where('status', 'new')->where('direction', 'in')
        ->when($notifSeen['messages'], fn ($q) => $q->where('created_at', '>', $notifSeen['messages']))
        ->count();

    // Unified badge (Phase 5) — a tenant expects "new message" to mean
    // either channel, not Messenger only. Guarded the same way every other
    // WhatsApp-aware call site is (chunk26.sql may not be imported yet on
    // every environment) so this layout, rendered on every panel page,
    // never 500s for a tenant who hasn't reached WhatsApp at all.
    if (\App\Models\WhatsAppPhoneNumber::tablesReady()) {
        $notifNewMessages += \App\Models\WhatsAppMessage::where('status', 'new')->where('direction', 'in')
            ->when($notifSeen['messages'], fn ($q) => $q->where('created_at', '>', $notifSeen['messages']))
            ->count();
    }
    $notifNewIncomplete = \App\Models\IncompleteOrder::where('status', 'abandoned')
        ->when($notifSeen['incomplete'], fn ($q) => $q->where('created_at', '>', $notifSeen['incomplete']))
        ->count();
    $notifTotal = $notifPendingOrders + $notifLowStock + $notifNewMessages + $notifNewIncomplete;
@endphp

<div class="min-h-screen lg:flex">

    {{-- sidebar --}}
    <aside class="lg:w-64 bg-ink text-white lg:min-h-screen">
        <div class="p-4 border-b border-white/10 lg:border-0 flex items-center justify-between gap-2">
            <a href="{{ route('tenant.dashboard') }}"
               class="flex items-center gap-2 min-w-0 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink">
                @if (app('currentTenant')->logo_path)
                    {{-- onerror removes just the broken image (e.g. a dead
                         storage symlink) — the store name next to it must
                         stay visible either way, never hidden by a logo
                         that failed to load. --}}
                    <img src="{{ asset('storage/' . app('currentTenant')->logo_path) }}"
                         alt="{{ app('currentTenant')->store_name }}"
                         class="h-8 max-w-[120px] object-contain bg-white/95 rounded px-2 py-1 shrink-0"
                         onerror="this.remove()">
                @endif
                <p class="font-disp font-bold text-lg leading-tight truncate">{{ app('currentTenant')->store_name }}</p>
            </a>

            {{-- Mobile-only — desktop already has the equivalent "শপ দেখুন"
                 link in the sidebar nav below (line ~140). --}}
            <a href="{{ app('currentTenant')->url() }}" target="_blank"
               class="lg:hidden shrink-0 flex items-center gap-1.5 bg-white text-ink text-xs font-semibold px-3 py-1.5 rounded-full hover:bg-white/90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink">
                <i data-lucide="external-link" class="w-3.5 h-3.5"></i> ওয়েবসাইট দেখুন
            </a>
        </div>

        <nav id="navMenu" class="hidden lg:block pb-4 text-sm">
            @php
                $tenant = app('currentTenant');
                $groups = [
                    'সারসংক্ষেপ' => [
                        ['tenant.dashboard', 'ড্যাশবোর্ড', 'layout-dashboard'],
                        ['tenant.ai-chat', 'Personal Assistant', 'bot'],
                    ],
                    'বিক্রি' => array_filter([
                        $tenant->plan?->allow_pos ? ['tenant.pos', 'POS বিক্রি', 'calculator'] : null,
                        ['tenant.orders.index', 'অর্ডার', 'receipt'],
                        ['tenant.incomplete', 'অসম্পূর্ণ অর্ডার', 'phone-missed'],
                        // The WhatsApp-style unified inbox (Messenger +
                        // WhatsApp together, filterable by channel) is now
                        // the one primary nav entry for messaging — the
                        // older Messenger-only list (tenant.messenger.index)
                        // is intentionally not linked here anymore, per the
                        // inbox redesign. Its route/controller/view are
                        // untouched and still work if reached directly.
                        ['tenant.inbox', 'ইনবক্স', 'inbox'],
                    ]),
                    'প্রোডাক্ট' => [
                        ['tenant.products.index', 'প্রোডাক্ট', 'package'],
                        ['tenant.categories.index', 'ক্যাটাগরি', 'folder-tree'],
                        ['tenant.inventory', 'ইনভেন্টরি', 'warehouse'],
                        ['tenant.inventory.low', 'লো স্টক', 'triangle-alert'],
                    ],
                    'কাস্টমার ও অর্থ' => [
                        ['tenant.customers.index', 'কাস্টমার ও বাকি', 'users'],
                        ['tenant.expenses.index', 'খরচ', 'wallet'],
                        ['tenant.billing', 'বিলিং', 'credit-card'],
                    ],
                    'গ্রোথ' => array_filter([
                        ['tenant.reports.sales', 'রিপোর্ট', 'bar-chart-3'],
                        ['tenant.product-source.index', 'প্রোডাক্ট সোর্স', 'search'],
                        ['tenant.website', 'ওয়েবসাইট সেটিংস', 'palette'],
                        $adEnabled ? ['tenant.advertising.overview', 'অ্যাডভার্টাইজিং', 'megaphone'] : null,
                    ]),
                    'অন্যান্য' => [
                        ['tenant.settings', 'সেটিংস', 'settings'],
                    ],
                ];
            @endphp

            @foreach ($groups as $groupLabel => $items)
                @if (count($items))
                    <p class="nav-group-label">{{ $groupLabel }}</p>
                    @foreach ($items as [$route, $label, $icon])
                        @php $isActive = request()->routeIs(str_replace('.index', '', $route) . '*'); @endphp
                        <a href="{{ route($route) }}"
                           class="flex items-center gap-3 mx-2 px-3.5 py-2.5 rounded-btn transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink {{ $isActive ? 'bg-white/10 text-white font-medium' : 'text-white/75 hover:bg-white/5 hover:text-white' }}">
                            <i data-lucide="{{ $icon }}" class="w-[18px] h-[18px] shrink-0 {{ $isActive ? 'text-amber' : '' }}"></i>
                            {{ $label }}
                        </a>
                    @endforeach
                @endif
            @endforeach

            @if (request()->routeIs('tenant.reports.*'))
                <div class="bg-black/20 py-1 mx-2 rounded-btn">
                    @foreach ([['tenant.reports.sales','বিক্রয়'],['tenant.reports.pl','লাভ-ক্ষতি'],['tenant.reports.locations','এলাকা'],['tenant.reports.products','প্রোডাক্ট']] as [$r,$l])
                        <a href="{{ route($r) }}" class="block pl-10 pr-4 py-1.5 text-xs rounded transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink {{ request()->routeIs($r) ? 'text-amber font-semibold' : 'text-white/70 hover:text-white' }}">{{ $l }}</a>
                    @endforeach
                </div>
            @endif

            @if ($adEnabled && request()->routeIs('tenant.advertising.*'))
                <div class="bg-black/20 py-1 mx-2 rounded-btn">
                    @foreach ([['tenant.advertising.overview','ওভারভিউ'],['tenant.advertising.ledger','ব্যালেন্স / লেজার'],['tenant.advertising.payments','পেমেন্টস'],['tenant.advertising.charges','চার্জেস']] as [$r,$l])
                        <a href="{{ route($r) }}" class="block pl-10 pr-4 py-1.5 text-xs rounded transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink {{ request()->routeIs($r) ? 'text-amber font-semibold' : 'text-white/70 hover:text-white' }}">{{ $l }}</a>
                    @endforeach
                </div>
            @endif

            <div class="border-t border-white/10 mt-3 pt-3 px-4 space-y-1">
                <a href="{{ app('currentTenant')->url() }}" target="_blank"
                   class="flex items-center gap-2 -mx-2 px-2 py-1.5 rounded-btn text-white/70 hover:text-white hover:bg-white/5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink">
                    <i data-lucide="external-link" class="w-4 h-4"></i> শপ দেখুন
                </a>
                <form method="POST" action="{{ route('tenant.logout') }}">
                    @csrf
                    <button class="flex items-center gap-2 -mx-2 px-2 py-1.5 rounded-btn text-white/70 hover:text-white hover:bg-white/5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink">
                        <i data-lucide="log-out" class="w-4 h-4"></i> লগআউট
                    </button>
                </form>
            </div>
        </nav>
    </aside>

    {{-- main column: top bar + page content --}}
    <div class="flex-1 flex flex-col min-w-0">
        <header class="sticky top-0 z-40 h-14 lg:h-16 shrink-0 bg-white/90 backdrop-blur border-b border-ink/5 flex items-center gap-3 px-4 lg:px-8">
            <button id="navToggle" class="lg:hidden w-9 h-9 -ml-1.5 grid place-items-center rounded-lg hover:bg-ink/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                <i data-lucide="menu" class="w-5 h-5" id="navToggleOpenIcon"></i>
                <i data-lucide="x" class="w-5 h-5 hidden" id="navToggleCloseIcon"></i>
            </button>

            <p class="font-bold text-sm lg:text-base truncate">@yield('title', 'প্যানেল')</p>

            @if ($adEnabled)
                {{-- Compact wallet indicator only — full balance/budget/rate detail lives in the Advertising module, never here --}}
                <a href="{{ route('tenant.advertising.overview') }}"
                   title="বর্তমান ব্যালেন্স"
                   class="ml-auto flex items-center gap-1.5 px-3 py-1.5 rounded-pill border border-ink/10 bg-paper/80 hover:bg-paper transition text-xs sm:text-sm font-semibold text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 {{ $adBalance !== null && (float) $adBalance <= 0 ? 'border-red-200 text-red-600' : '' }}">
                    <span>💰</span><span>৳{{ number_format((float) $adBalance) }}</span>
                </a>
            @endif

            <div class="relative {{ $adEnabled ? '' : 'ml-auto' }}">
                <button id="notifBtn" data-seen-url="{{ route('tenant.notifications.seen') }}" data-csrf="{{ csrf_token() }}"
                        class="relative w-10 h-10 rounded-full hover:bg-ink/5 grid place-items-center transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                    <i data-lucide="bell" class="w-5 h-5 text-leaf"></i>
                    <span id="notifBadge" class="absolute top-1 right-1 bg-red-600 text-white text-[10px] font-bold w-4.5 h-4.5 rounded-full grid place-items-center {{ $notifTotal > 0 ? '' : 'hidden' }}">{{ $notifTotal > 9 ? '9+' : $notifTotal }}</span>
                </button>
                <div id="notifPanel" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-card shadow-xl border border-ink/10 overflow-hidden">
                    <div class="px-4 py-3 border-b border-ink/5 font-bold text-sm">নোটিফিকেশন</div>
                    <div class="max-h-96 overflow-y-auto divide-y divide-ink/5">
                        @if ($notifPendingOrders > 0)
                            <a href="{{ route('tenant.orders.index', ['status' => 'pending']) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm transition">
                                <i data-lucide="clock" class="w-4 h-4 text-amber shrink-0"></i>
                                <span class="flex-1">{{ $notifPendingOrders }}টি অর্ডার কনফার্মেশনের অপেক্ষায়</span>
                            </a>
                        @endif
                        @if ($notifLowStock > 0)
                            <a href="{{ route('tenant.inventory.low') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm transition">
                                <i data-lucide="triangle-alert" class="w-4 h-4 text-red-600 shrink-0"></i>
                                <span class="flex-1">{{ $notifLowStock }}টি প্রোডাক্টের স্টক কম</span>
                            </a>
                        @endif
                        @if ($notifNewMessages > 0)
                            <a href="{{ route('tenant.messenger.index') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm transition">
                                <i data-lucide="message-circle" class="w-4 h-4 text-blue-600 shrink-0"></i>
                                <span class="flex-1">{{ $notifNewMessages }}টি নতুন মেসেঞ্জার মেসেজ</span>
                            </a>
                        @endif
                        @if ($notifNewIncomplete > 0)
                            <a href="{{ route('tenant.incomplete') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm transition">
                                <i data-lucide="phone-missed" class="w-4 h-4 text-mute shrink-0"></i>
                                <span class="flex-1">{{ $notifNewIncomplete }}টি অসম্পূর্ণ অর্ডার</span>
                            </a>
                        @endif
                        @if ($notifTotal === 0)
                            <p class="px-4 py-8 text-center text-mute text-sm">সব ঠিক আছে ✓ কোনো নোটিফিকেশন নেই</p>
                        @endif
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-8 pb-24 lg:pb-8">
            {{-- Persistent-login tip: shown once, purely client-side
                 (localStorage, no backend state). Android OEM battery /
                 autostart managers (Xiaomi, Vivo, Oppo, Realme — common on
                 this product's target devices) routinely clear an installed
                 PWA's cookies in the background, forcing an unexpected
                 re-login that no server-side session/cookie fix alone can
                 prevent. See the mobile audit, Part B, for the full
                 reasoning — this banner is the user-education half of that
                 mitigation. --}}
            <div id="signinTip" class="hidden mb-6">
                <x-ui.card padding="sm">
                    <div class="flex items-start gap-3 text-sm">
                        <i data-lucide="shield-check" class="w-4 h-4 text-leafdk shrink-0 mt-0.5"></i>
                        <div class="flex-1">
                            <p class="font-semibold mb-1">সবসময় লগইন থাকতে</p>
                            <p class="text-mute">ফোনের Settings → Apps → Chrome-এ গিয়ে <b>Autostart</b> চালু বা <b>Battery optimization</b> বন্ধ করে দিন — তাহলে অ্যাপ বন্ধ করলে বা ফোন রিস্টার্ট করলেও বারবার লগইন করতে হবে না।</p>
                        </div>
                        <button id="signinTipDismiss" type="button" class="shrink-0 text-mute hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 rounded" aria-label="বন্ধ করুন">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                </x-ui.card>
            </div>

            {{-- Notification-permission value-prop banner (mobile audit,
                 Part 4/Phase 4): never trigger the native browser permission
                 prompt on page load. This is our own dismissible card —
                 only the "নোটিফিকেশন চালু করুন" button click (a real user
                 gesture, which browsers require anyway for the native
                 prompt to have any effect) calls Notification.requestPermission()
                 in resources/js/app.js's push-notifications section.
                 data-* attributes carry only public, non-secret values —
                 the VAPID *public* key is meant to ship to the browser (it's
                 how the browser proves a subscription belongs to this app's
                 private key, not a credential in itself). --}}
            <div id="pushPromptTip" class="hidden mb-6">
                <x-ui.card padding="sm">
                    <div class="flex items-start gap-3 text-sm">
                        <i data-lucide="bell-ring" class="w-4 h-4 text-leafdk shrink-0 mt-0.5"></i>
                        <div class="flex-1">
                            <p class="font-semibold mb-1">নোটিফিকেশন চালু করুন</p>
                            <p class="text-mute mb-2.5">নতুন অর্ডার, কাস্টমার মেসেজ এবং জরুরি বিজনেস আপডেট সম্পর্কে সাথে সাথে জানতে পারবেন।</p>
                            <div class="flex gap-2">
                                <button id="pushPromptAllow" type="button" class="text-xs font-semibold bg-leaf text-white px-3.5 py-1.5 rounded-pill hover:bg-leafdk transition">নোটিফিকেশন চালু করুন</button>
                                <button id="pushPromptLater" type="button" class="text-xs font-semibold text-mute hover:text-ink px-3.5 py-1.5">পরে করব</button>
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            </div>

            @yield('content')
        </main>
    </div>
</div>

{{-- mobile bottom tab bar — background is this tenant's own brand color
     (primary_color, same token the storefront uses), with text/icon color
     auto-picked for contrast (Tenant::primaryColorContrastText()) so
     legibility never depends on which hex a tenant happened to pick. Home
     is raised above the bar's own baseline (a negative top margin, not a
     height change on the <nav> itself) with a subtle drop shadow and a
     faint contrast-matched bubble behind it — every other tab keeps its
     original size, only Home is emphasized. --}}
@php
    $navBg = app('currentTenant')->primary_color ?: '#128155';
    $navFg = app('currentTenant')->primaryColorContrastText();
    $navFgMuted = $navFg === '#ffffff' ? 'rgba(255,255,255,0.68)' : 'rgba(17,17,17,0.6)';
    $homeBubbleBg = $navFg === '#ffffff' ? 'rgba(255,255,255,0.16)' : 'rgba(0,0,0,0.07)';
@endphp
<nav class="lg:hidden fixed bottom-0 left-0 right-0 flex items-end justify-around pt-1.5 pb-[calc(0.375rem+env(safe-area-inset-bottom))] pl-[env(safe-area-inset-left)] pr-[env(safe-area-inset-right)] z-30 shadow-[0_-2px_10px_rgba(0,0,0,0.1)]"
     style="background-color: {{ $navBg }};">
    @php
        // POS stays fully available (desktop sidebar + mobile hamburger menu,
        // routes/controller/permissions untouched) — this bottom bar is only a
        // quick-access shortcut row, and the inbox is the more frequently
        // needed shortcut there on mobile. Points at the unified inbox
        // (Messenger + WhatsApp) now, same nav-demotion as the sidebar above.
        $mobileTabs = [
            ['tenant.dashboard', 'হোম', 'layout-dashboard'],
            ['tenant.orders.index', 'অর্ডার', 'receipt'],
            ['tenant.inbox', 'ইনবক্স', 'inbox'],
            ['tenant.customers.index', 'কাস্টমার', 'users'],
            ['tenant.settings', 'সেটিংস', 'settings'],
        ];
    @endphp
    @foreach ($mobileTabs as [$route, $label, $icon])
        @php
            $isActive = request()->routeIs(str_replace('.index', '', $route) . '*');
            $isHome = $route === 'tenant.dashboard';
        @endphp
        {{-- Active = full-strength text color only, matching the reference —
             no pill/background highlight on non-Home tabs. --}}
        <a href="{{ route($route) }}" class="flex flex-col items-center gap-1 px-2 py-1 rounded-btn transition-transform active:scale-[0.95] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 {{ $isHome ? '-mt-3.5' : '' }}"
           style="color: {{ $isActive ? $navFg : $navFgMuted }};">
            <span @if ($isHome) class="w-11 h-11 rounded-full grid place-items-center shadow-[0_3px_8px_rgba(0,0,0,0.3)] ring-2 ring-black/5" style="background-color: {{ $homeBubbleBg }};" @endif>
                <i data-lucide="{{ $icon }}" class="{{ $isHome ? 'w-5 h-5' : 'w-4 h-4' }}"></i>
            </span>
            <span class="text-[10px] {{ $isActive ? 'font-semibold' : 'font-medium' }}">{{ $label }}</span>
        </a>
    @endforeach
</nav>

{{-- toast notifications --}}
<div id="toastStack"></div>

@php
    $flashMessages = array_filter([
        session('success') ? ['message' => session('success'), 'type' => 'success'] : null,
        session('error') ? ['message' => session('error'), 'type' => 'error'] : null,
        session('warning') ? ['message' => session('warning'), 'type' => 'error'] : null,
        $errors->any() ? ['message' => $errors->first(), 'type' => 'error'] : null,
    ]);
@endphp

<script>
    lucide.createIcons();

    {{-- showToast() lives in the bundled resources/js/app.js module, which
    loads deferred and therefore always executes AFTER this synchronous
    inline script — calling showToast() directly here would throw
    "showToast is not defined". Queuing the data instead (a plain
    assignment, no function call) sidesteps the ordering issue entirely:
    app.js drains this queue itself right after it defines showToast. --}}
    window.__flashMessages = @js(array_values($flashMessages));
    window.__swUrl = @js(route('tenant.pwa.sw'));

    {{-- Consumed by the "Push notifications" section of resources/js/app.js
    — reused as-is by both the dismissible banner above and the manual
    "notification enable" control on the Settings → Notifications page, so
    there's exactly one subscribe() implementation. vapidKey is a *public*
    key (safe to ship to the browser, not a credential); null until VAPID
    keys are generated in production — app.js treats null as "push isn't
    configured yet" and simply never shows the banner. --}}
    window.__push = {
        vapidKey: @js(config('services.vapid.public_key')),
        subscribeUrl: @js(route('tenant.push.subscribe')),
        csrf: @js(csrf_token()),
    };

    {{-- Splash hide: fires the instant this synchronous script runs (right
    after the DOM above it has parsed), never waiting on the deferred
    module or a timer — "hide as soon as ready" with no artificial delay.
    Also a safety net for browsers with JS disabled/blocked: nothing here
    depends on app.js loading successfully to get the splash out of the way. --}}
    document.getElementById('appSplash')?.classList.add('is-hidden');
    setTimeout(() => document.getElementById('appSplash')?.remove(), 300);

    {{-- Show the persistent-login tip once, ever, per browser — dismissing
    it (or having already seen it) is remembered in localStorage only, so
    there's no server round-trip and no per-user DB state to add for a
    purely cosmetic, skippable hint. --}}
    (function () {
        var tip = document.getElementById('signinTip');
        if (!tip) return;
        var key = 'ms_signin_tip_dismissed';
        try {
            if (!localStorage.getItem(key)) {
                tip.classList.remove('hidden');
            }
            document.getElementById('signinTipDismiss')?.addEventListener('click', function () {
                tip.classList.add('hidden');
                localStorage.setItem(key, '1');
            });
        } catch (e) {
            // Private-browsing/storage-blocked: fail silently, tip just stays hidden.
        }
    })();
</script>
@stack('scripts')
</body>
</html>
