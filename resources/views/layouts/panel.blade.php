<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'প্যানেল') — {{ app('currentTenant')->store_name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <script>
        tailwind.config = { theme: { extend: {
            colors: { ink:'#132A21', leaf:'#128155', leafdk:'#0C5C3C', paper:'#F4F2EA', amber:'#F5B31A', mute:'#5C6B63' },
            fontFamily: { body:['"Hind Siliguri"','sans-serif'], disp:['"Noto Serif Bengali"','serif'] },
        }}};
    </script>
    <style>
        .card-hover { transition: transform .15s ease, box-shadow .15s ease; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -6px rgba(19,42,33,0.12); }
        .btn-loading { position: relative; pointer-events: none; opacity: .75; }
        .btn-loading .btn-spinner { display: inline-block; }
        .btn-spinner { display:none; width:16px; height:16px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation: spin .6s linear infinite; margin-right:6px; vertical-align:-3px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        #toastStack { position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
        .toast { min-width: 260px; max-width: 360px; padding: 12px 16px; border-radius: 10px; font-size: 14px; box-shadow: 0 10px 25px -8px rgba(19,42,33,0.25); animation: toastIn .25s ease; }
        @keyframes toastIn { from { opacity:0; transform: translateX(20px);} to { opacity:1; transform: translateX(0);} }
        .toast.leaving { animation: toastOut .2s ease forwards; }
        @keyframes toastOut { to { opacity:0; transform: translateX(20px);} }
        .nav-group-label { font-size:11px; letter-spacing:.06em; color:rgba(255,255,255,.4); padding:14px 16px 4px; text-transform:uppercase; }
    </style>
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

{{-- notification bell --}}
<div class="fixed top-3 right-3 z-50">
    <button id="notifBtn" class="relative w-11 h-11 rounded-full bg-white shadow-md border border-ink/10 grid place-items-center hover:border-leaf/40">
        <i data-lucide="bell" class="w-5 h-5 text-ink"></i>
        @if ($notifTotal > 0)
            <span class="absolute -top-1 -right-1 bg-red-600 text-white text-[10px] font-bold w-5 h-5 rounded-full grid place-items-center">{{ $notifTotal > 9 ? '9+' : $notifTotal }}</span>
        @endif
    </button>
    <div id="notifPanel" class="hidden absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-ink/10 overflow-hidden">
        <div class="px-4 py-3 border-b border-ink/5 font-bold text-sm">নোটিফিকেশন</div>
        <div class="max-h-96 overflow-y-auto divide-y divide-ink/5">
            @if ($notifPendingOrders > 0)
                <a href="{{ route('tenant.orders.index', ['status' => 'pending']) }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm">
                    <i data-lucide="clock" class="w-4 h-4 text-amber shrink-0"></i>
                    <span class="flex-1">{{ $notifPendingOrders }}টি অর্ডার কনফার্মেশনের অপেক্ষায়</span>
                </a>
            @endif
            @if ($notifLowStock > 0)
                <a href="{{ route('tenant.inventory.low') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm">
                    <i data-lucide="triangle-alert" class="w-4 h-4 text-red-600 shrink-0"></i>
                    <span class="flex-1">{{ $notifLowStock }}টি প্রোডাক্টের স্টক কম</span>
                </a>
            @endif
            @if ($notifNewMessages > 0)
                <a href="{{ route('tenant.messenger.index') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm">
                    <i data-lucide="message-circle" class="w-4 h-4 text-blue-600 shrink-0"></i>
                    <span class="flex-1">{{ $notifNewMessages }}টি নতুন মেসেঞ্জার মেসেজ</span>
                </a>
            @endif
            @if ($notifNewIncomplete > 0)
                <a href="{{ route('tenant.incomplete') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-paper/60 text-sm">
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

<div class="min-h-screen lg:flex">

    {{-- sidebar --}}
    <aside class="lg:w-64 bg-ink text-white lg:min-h-screen">
        <div class="p-4 flex items-center justify-between lg:block border-b border-white/10 lg:border-0">
            <p class="font-disp font-bold text-lg leading-tight">{{ app('currentTenant')->store_name }}</p>
            <button id="navToggle" class="lg:hidden text-2xl">☰</button>
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
                        <a href="{{ route($route) }}"
                           class="flex items-center gap-3 px-4 py-2.5 hover:bg-white/10 transition {{ request()->routeIs(str_replace('.index','',$route).'*') ? 'bg-white/10 border-l-2 border-amber' : '' }}">
                            <i data-lucide="{{ $icon }}" class="w-[18px] h-[18px] shrink-0"></i> {{ $label }}
                        </a>
                    @endforeach
                @endif
            @endforeach

            @if (request()->routeIs('tenant.reports.*'))
                <div class="bg-black/20 py-1">
                    @foreach ([['tenant.reports.sales','বিক্রয়'],['tenant.reports.pl','লাভ-ক্ষতি'],['tenant.reports.locations','এলাকা'],['tenant.reports.products','প্রোডাক্ট']] as [$r,$l])
                        <a href="{{ route($r) }}" class="block pl-12 pr-4 py-1.5 text-xs {{ request()->routeIs($r) ? 'text-amber font-semibold' : 'text-white/70 hover:text-white' }}">{{ $l }}</a>
                    @endforeach
                </div>
            @endif

            <div class="border-t border-white/10 mt-3 pt-3 px-4 space-y-2">
                <a href="{{ app('currentTenant')->url() }}" target="_blank" class="flex items-center gap-2 text-white/70 hover:text-white">
                    <i data-lucide="external-link" class="w-4 h-4"></i> দোকান দেখুন
                </a>
                <form method="POST" action="{{ route('tenant.logout') }}">@csrf
                    <button class="flex items-center gap-2 text-white/70 hover:text-white">
                        <i data-lucide="log-out" class="w-4 h-4"></i> লগআউট
                    </button>
                </form>
            </div>
        </nav>
    </aside>

    {{-- main --}}
    <main class="flex-1 p-4 lg:p-8 pb-20 lg:pb-8">
        @yield('content')
    </main>
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
        <a href="{{ route($route) }}" class="flex flex-col items-center gap-0.5 px-2 {{ request()->routeIs(str_replace('.index','',$route).'*') ? 'text-leaf' : 'text-mute' }}">
            <i data-lucide="{{ $icon }}" class="w-5 h-5"></i>
            <span class="text-[10px]">{{ $label }}</span>
        </a>
    @endforeach
</nav>

{{-- toast notifications --}}
<div id="toastStack"></div>

<script>
    document.getElementById('navToggle')?.addEventListener('click', () =>
        document.getElementById('navMenu').classList.toggle('hidden'));

    // ---- notification bell ----
    const notifBtn = document.getElementById('notifBtn');
    const notifPanel = document.getElementById('notifPanel');
    notifBtn?.addEventListener('click', (e) => {
        e.stopPropagation();
        notifPanel.classList.toggle('hidden');
    });
    document.addEventListener('click', (e) => {
        if (!notifPanel?.contains(e.target) && e.target !== notifBtn) notifPanel?.classList.add('hidden');
    });

    lucide.createIcons();

    // ---- toast system ----
    function showToast(message, type = 'success') {
        const stack = document.getElementById('toastStack');
        const el = document.createElement('div');
        const styles = {
            success: 'bg-white border border-leaf/30 text-leafdk',
            error:   'bg-white border border-red-200 text-red-700',
        };
        el.className = 'toast ' + (styles[type] || styles.success);
        el.innerHTML = (type === 'error' ? '⚠️ ' : '✅ ') + message;
        stack.appendChild(el);
        setTimeout(() => { el.classList.add('leaving'); setTimeout(() => el.remove(), 200); }, 4000);
    }

    @if (session('success'))
        showToast(@json(session('success')), 'success');
    @endif
    @if (session('error'))
        showToast(@json(session('error')), 'error');
    @endif
    @if (session('warning'))
        showToast(@json(session('warning')), 'error');
    @endif
    @if ($errors->any())
        showToast(@json($errors->first()), 'error');
    @endif

    // ---- button loading state on form submit ----
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function () {
            if (form.dataset.noLoading) return;
            const btn = form.querySelector('button[type="submit"], button:not([type])');
            if (btn && !btn.classList.contains('btn-loading')) {
                btn.dataset.originalText = btn.innerHTML;
                btn.classList.add('btn-loading');
                btn.innerHTML = '<span class="btn-spinner"></span>' + btn.innerHTML;
            }
        });
    });
</script>
@stack('scripts')
</body>
</html>
