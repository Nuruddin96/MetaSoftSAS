@php
    $navLinks = [
        ['Home', '#top', 'home'],
        ['Brands', '#brands', 'store'],
        ['Entrepreneurs', '#entrepreneurs', 'users'],
        ['Awards', '#awards', 'trophy'],
        ['Voting', '#voting', 'vote'],
        ['Events', '#events', 'calendar'],
        ['Media', '#stories', 'newspaper'],
    ];
@endphp

{{-- Utility strip: live award + sample-preview notice --}}
<div class="bg-night text-white">
    <div class="{{ $wrap }} flex items-center justify-between gap-4 py-2 text-xs">
        <a href="#awards" class="flex min-w-0 items-center gap-2 rounded hover:text-gold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">
            <span class="relative flex h-2 w-2 shrink-0"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-flag opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-flag"></span></span>
            <span class="truncate text-white/85">Awards 2026 · Public voting closes 30 Nov</span>
            <span class="hidden shrink-0 font-bold text-gold sm:inline">Vote now →</span>
        </a>
        <div class="flex shrink-0 items-center gap-4">
            @if($preview)
                <span class="hidden rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white/80 md:inline" title="Brands, people and numbers on this page are sample content.">Preview · sample data</span>
            @endif
            <a href="#recognition" class="hidden text-white/70 hover:text-white md:inline">Award rules</a>
            <a href="#sponsors" class="hidden text-white/70 hover:text-white md:inline">For sponsors</a>
            <span class="font-body text-white/70" lang="bn">বাংলা + English</span>
        </div>
    </div>
</div>

{{-- Main navigation --}}
<header id="siteHeader" class="sticky top-0 z-40 border-b border-hair bg-white/90 backdrop-blur-md">
    <div class="{{ $wrap }} flex h-16 items-center justify-between gap-4 lg:h-[72px]">
        <a href="{{ url('/') }}" class="flex items-center gap-2.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            <x-ui.brand-mark />
            <span class="leading-none">
                <span class="block text-[17px] font-extrabold tracking-tight text-night">MetaSoft BD</span>
                <span class="mt-1 hidden whitespace-nowrap text-[9.5px] font-bold uppercase tracking-[0.16em] text-leaf sm:block">Brand & Entrepreneur Network</span>
            </span>
        </a>

        <nav class="hidden items-center gap-1 lg:flex" aria-label="Primary">
            @foreach($navLinks as [$label, $href])
                <a href="{{ $href }}" data-nav="{{ ltrim($href, '#') }}"
                   class="relative flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-2 text-[14.5px] xl:px-3 font-semibold text-slate2 transition hover:bg-cloud hover:text-night focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf aria-[current=true]:text-night">
                    {{ $label }}
                    @if($label === 'Voting')<span class="rounded bg-flag px-1.5 py-px text-[9px] font-extrabold tracking-wide text-white">LIVE</span>@endif
                </a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <a href="{{ route('automation') }}"
               class="group hidden items-center gap-2 rounded-xl bg-navy py-2 pl-2 pr-3 text-white transition hover:bg-night focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 md:flex">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-sky-500"><x-plat.icon name="zap" class="w-4 h-4" /></span>
                <span class="whitespace-nowrap text-[13px] font-bold">Business Automation</span>
                <x-plat.icon name="arrow-up-right" class="w-3.5 h-3.5 opacity-60 transition group-hover:opacity-100" />
            </a>
            <a href="{{ route('central.login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-semibold text-night hover:bg-cloud focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:inline-flex">Login</a>
            <a href="{{ $joinUrl }}" target="_blank" rel="noopener"
               class="hidden whitespace-nowrap rounded-xl bg-leaf px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 sm:inline-flex">
                List your brand
            </a>
            <button type="button" id="menuOpen" aria-controls="mobileMenu" aria-expanded="false"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-hair text-night lg:hidden focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                <x-plat.icon name="menu" class="w-5 h-5" /><span class="sr-only">Open menu</span>
            </button>
        </div>
    </div>
</header>

{{-- Mobile drawer --}}
<div id="mobileMenu" class="fixed inset-0 z-[60] hidden lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="absolute inset-0 bg-night/50 backdrop-blur-sm" data-close-menu></div>
    <div class="absolute inset-y-0 right-0 flex w-[86%] max-w-sm flex-col bg-white shadow-2xl">
        <div class="flex h-16 items-center justify-between border-b border-hair px-4">
            <span class="flex items-center gap-2 font-extrabold"><x-ui.brand-mark size="sm" /> MetaSoft BD</span>
            <button type="button" data-close-menu class="flex h-10 w-10 items-center justify-center rounded-xl border border-hair focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                <x-plat.icon name="x" class="w-5 h-5" /><span class="sr-only">Close menu</span>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto px-4 py-4">
            <form action="{{ url('/') }}" method="get" class="mb-4 flex items-center gap-2 rounded-xl border border-hair bg-cloud px-3" role="search">
                <x-plat.icon name="search" class="w-4 h-4 text-slate2" />
                <label for="mSearch" class="sr-only">Search</label>
                <input id="mSearch" name="q" type="search" placeholder="Search brands, entrepreneurs…" class="h-11 w-full bg-transparent text-sm outline-none placeholder:text-slate2">
            </form>
            <nav class="space-y-1" aria-label="Mobile">
                @foreach($navLinks as [$label, $href, $icon])
                    <a href="{{ $href }}" data-close-menu class="flex items-center gap-3 rounded-xl px-3 py-3 text-[15px] font-semibold text-night hover:bg-cloud">
                        <x-plat.icon :name="$icon" class="w-5 h-5 text-leaf" /> {{ $label }}
                        @if($label === 'Voting')<span class="ml-auto rounded bg-flag px-1.5 py-px text-[10px] font-extrabold text-white">LIVE</span>@endif
                    </a>
                @endforeach
            </nav>
            <a href="{{ route('automation') }}" class="mt-4 flex items-center gap-3 rounded-2xl bg-navy p-4 text-white">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-sky-500"><x-plat.icon name="zap" class="w-5 h-5" /></span>
                <span><span class="block text-sm font-bold">Business Automation</span><span class="text-xs text-white/60">Store, POS, courier & marketing tools</span></span>
            </a>
        </div>
        <div class="grid grid-cols-2 gap-2 border-t border-hair p-4">
            <a href="{{ route('central.login') }}" class="rounded-xl border border-hair py-3 text-center text-sm font-bold">Login</a>
            <a href="{{ $joinUrl }}" target="_blank" rel="noopener" class="rounded-xl bg-leaf py-3 text-center text-sm font-bold text-white">List your brand</a>
        </div>
    </div>
</div>

{{-- Mobile bottom tab bar: the app-like shortcuts mobile visitors need most --}}
<nav class="fixed inset-x-0 bottom-0 z-40 border-t border-hair bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden" aria-label="Quick">
    <div class="mx-auto grid max-w-md grid-cols-5">
        @foreach([['Home', '#top', 'home'], ['Brands', '#brands', 'store'], ['Vote', '#voting', 'vote'], ['Awards', '#awards', 'trophy'], ['Join', '#join', 'user-plus']] as [$label, $href, $icon])
            <a href="{{ $href }}" class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-semibold {{ $label === 'Vote' ? 'text-leaf' : 'text-slate2' }} hover:text-night">
                @if($label === 'Vote')
                    <span class="-mt-6 flex h-12 w-12 items-center justify-center rounded-2xl bg-leaf text-white shadow-lg shadow-leaf/30 ring-4 ring-white"><x-plat.icon name="vote" class="w-5 h-5" /></span>
                @else
                    <x-plat.icon :name="$icon" class="w-5 h-5" />
                @endif
                {{ $label }}
            </a>
        @endforeach
    </div>
</nav>
