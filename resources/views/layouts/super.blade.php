<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'সুপার অ্যাডমিন') — MetaSoft BD</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Super admin uses a distinct dark-navy ink/paper, different from the shared central/panel palette. --}}
    <style>:root { --color-ink: #1A1A2E; --color-paper: #F2F2F7; }</style>
</head>
<body class="font-body bg-paper text-ink antialiased">
<div class="min-h-screen lg:flex">
    <aside class="lg:w-60 bg-ink text-white lg:min-h-screen">
        <div class="p-4 flex items-center justify-between lg:block">
            <p class="font-disp font-bold text-lg">⚡ সুপার অ্যাডমিন</p>
            <button id="navToggle" class="lg:hidden text-2xl">☰</button>
        </div>
        <nav id="navMenu" class="hidden lg:block pb-4 text-sm">
            @php $nav = [
                ['super.dashboard', 'ড্যাশবোর্ড', '📊'],
                ['super.tenants', 'টেনেন্ট', '🏪'],
                ['super.payments', 'সাস পেমেন্ট', '💰'],
                ['super.clients.index', 'ক্লায়েন্ট', '🏢'],
                ['super.clients.payments', 'রেগুলার ক্লায়েন্ট পেমেন্ট', '💵'],
                ['super.plans', 'প্ল্যান', '📋'],
                ['super.source.products', 'সোর্স — পণ্য', '📦'],
                ['super.source.orders', 'সোর্স — অর্ডার', '📥'],
                ['super.affiliates', 'অ্যাফিলিয়েট', '💰'],
            ]; @endphp
            @foreach ($nav as [$route, $label, $icon])
                <a href="{{ route($route) }}"
                   class="flex items-center gap-3 px-4 py-2.5 hover:bg-white/10 {{ request()->routeIs(str_replace('.index','',$route).'*') ? 'bg-white/10 border-l-2 border-amber' : '' }}">
                    <span>{{ $icon }}</span> {{ $label }}
                </a>
            @endforeach
            <div class="border-t border-white/10 mt-3 pt-3 px-4">
                <form method="POST" action="{{ route('super.logout') }}">@csrf
                    <button class="text-white/70 hover:text-white">লগআউট</button>
                </form>
            </div>
        </nav>
    </aside>

    <main class="flex-1 p-4 lg:p-8">
        @if (session('success'))<div class="mb-4 bg-leaf/10 border border-leaf/30 text-leafdk rounded-lg px-4 py-3 text-sm">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">{{ session('error') }}</div>@endif
        @yield('content')
    </main>
</div>
<script>document.getElementById('navToggle')?.addEventListener('click', () => document.getElementById('navMenu').classList.toggle('hidden'));</script>
</body>
</html>
