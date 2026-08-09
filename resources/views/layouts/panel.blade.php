<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'প্যানেল') — {{ app('currentTenant')->store_name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-body bg-paper text-ink antialiased">
@php
    $notifTenant = app('currentTenant');
    $notifPendingOrders = \App\Models\Order::where('status', 'pending')->count();
    $notifLowStock = (int) \Illuminate\Support\Facades\DB::table('product_variants as pv')
        ->leftJoin('inventory as i', 'i.variant_id', '=', 'pv.id')
        ->where('pv.tenant_id', $notifTenant->id)
        ->select('pv.id')
        ->groupBy('pv.id', 'pv.low_stock_threshold')
        ->havingRaw('COALESCE(SUM(i.quantity), 0) <= pv.low_stock_threshold')
        ->get()->count();
    $notifNewMessages = \App\Models\MessengerMessage::where('status', 'new')->where('direction', 'in')->count();
    $notifNewIncomplete = \App\Models\IncompleteOrder::where('status', 'abandoned')->count();
    $notifTotal = $notifPendingOrders + $notifLowStock + $notifNewMessages + $notifNewIncomplete;
@endphp

<div class="min-h-screen lg:flex">

    {{-- sidebar --}}
    <aside class="lg:w-64 bg-ink text-white lg:min-h-screen">
        <div class="p-4 border-b border-white/10 lg:border-0">
            @if (app('currentTenant')->logo_path)
                <img src="{{ asset('storage/' . app('currentTenant')->logo_path) }}"
                     alt="{{ app('currentTenant')->store_name }}"
                     class="h-8 max-w-[160px] object-contain bg-white/95 rounded px-2 py-1">
            @else
                <p class="font-disp font-bold text-lg leading-tight">{{ app('currentTenant')->store_name }}</p>
            @endif
        </div>

        <nav id="navMenu" class="hidden lg:block pb-4 text-sm">
            @php
                $tenant = app('currentTenant');
                $groups = [
                    'সারসংক্ষেপ' => [
                        ['tenant.dashboard', 'ড্যাশবোর্ড', 'layout-dashboard'],
                    ],
                    'বিক্রি' => array_filter([
                        $tenant->plan?->allow_pos ? ['tenant.pos', 'POS বিক্রি', 'calculator'] : null,
                        ['tenant.orders.index', 'অর্ডার', 'receipt'],
                        ['tenant.incomplete', 'অসম্পূর্ণ অর্ডার', 'phone-missed'],
                        ['tenant.messenger.index', 'মেসেঞ্জার ইনবক্স', 'message-circle'],
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
                    'গ্রোথ' => [
                        ['tenant.reports.sales', 'রিপোর্ট', 'bar-chart-3'],
                        ['tenant.product-source.index', 'প্রোডাক্ট সোর্স', 'search'],
                        ['tenant.website', 'ওয়েবসাইট সেটিংস', 'palette'],
                    ],
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

            <div class="border-t border-white/10 mt-3 pt-3 px-4 space-y-1">
                <a href="{{ app('currentTenant')->url() }}" target="_blank"
                   class="flex items-center gap-2 -mx-2 px-2 py-1.5 rounded-btn text-white/70 hover:text-white hover:bg-white/5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber focus-visible:ring-offset-2 focus-visible:ring-offset-ink">
                    <i data-lucide="external-link" class="w-4 h-4"></i> দোকান দেখুন
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
        <header class="sticky top-0 z-40 h-16 shrink-0 bg-white/90 backdrop-blur border-b border-ink/5 flex items-center gap-3 px-4 lg:px-8">
            <button id="navToggle" class="lg:hidden w-9 h-9 -ml-1.5 grid place-items-center rounded-lg hover:bg-ink/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                <i data-lucide="menu" class="w-5 h-5" id="navToggleOpenIcon"></i>
                <i data-lucide="x" class="w-5 h-5 hidden" id="navToggleCloseIcon"></i>
            </button>

            <p class="font-bold text-sm lg:text-base truncate">@yield('title', 'প্যানেল')</p>

            <div class="relative ml-auto">
                <button id="notifBtn" class="relative w-10 h-10 rounded-full hover:bg-ink/5 grid place-items-center transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                    <i data-lucide="bell" class="w-5 h-5 text-ink"></i>
                    @if ($notifTotal > 0)
                        <span class="absolute top-1 right-1 bg-red-600 text-white text-[10px] font-bold w-4.5 h-4.5 rounded-full grid place-items-center">{{ $notifTotal > 9 ? '9+' : $notifTotal }}</span>
                    @endif
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

        <main class="flex-1 p-4 lg:p-8 pb-20 lg:pb-8">
            @yield('content')
        </main>
    </div>
</div>

{{-- mobile bottom tab bar --}}
<nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-ink/10 flex items-center justify-around py-2 z-30">
    @php
        $mobileTabs = [
            ['tenant.dashboard', 'হোম', 'layout-dashboard'],
            ['tenant.orders.index', 'অর্ডার', 'receipt'],
            $tenant->plan?->allow_pos ? ['tenant.pos', 'POS', 'calculator'] : ['tenant.products.index', 'প্রোডাক্ট', 'package'],
            ['tenant.customers.index', 'কাস্টমার', 'users'],
            ['tenant.settings', 'সেটিংস', 'settings'],
        ];
    @endphp
    @foreach ($mobileTabs as [$route, $label, $icon])
        @php $isActive = request()->routeIs(str_replace('.index', '', $route) . '*'); @endphp
        <a href="{{ route($route) }}" class="flex flex-col items-center gap-0.5 px-3 py-1 rounded-btn transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf {{ $isActive ? 'text-leaf bg-leaf/10' : 'text-mute' }}">
            <i data-lucide="{{ $icon }}" class="w-5 h-5"></i>
            <span class="text-[10px]">{{ $label }}</span>
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
</script>
@stack('scripts')
</body>
</html>
