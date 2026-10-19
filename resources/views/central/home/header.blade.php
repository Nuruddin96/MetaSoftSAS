@php
    // Concept C sections only; "About us" needs the credentials section, which shows once the certificate is supplied.
    $navLinks = array_values(array_filter([
        ['Home', '#top', 'home'],
        ['Brands', '#brands', 'store'],
        ['Verified', '#verified', 'shield'],
        ['Awards', '#awards', 'trophy'],
        ['Community', '#community', 'users'],
        $media['certificate'] ? ['About us', '#credentials', 'info'] : null,
    ]));
    $awardRulesUrl = ($award['live'] ?? false) ? $award['url'] : '#awards';
@endphp

{{-- Utility strip (tablet/desktop only, so the phone header stays compact): award name + sample-preview notice. The pulsing dot and "Vote now" need a server-confirmed open campaign ($votingOpen). --}}
<div class="hidden bg-night text-white sm:block">
    <div class="{{ $wrap }} flex items-center justify-between gap-4 py-2 text-xs">
        <a href="#awards" class="flex min-w-0 items-center gap-2 rounded hover:text-gold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">
            @if($votingOpen)<span class="relative flex h-2 w-2 shrink-0"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-flag opacity-75"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-flag"></span></span>@else<span class="h-2 w-2 shrink-0 rounded-full bg-gold"></span>@endif
            <span class="truncate text-white/85">{{ $home['announcement'] }}</span>
            <span class="hidden shrink-0 font-bold text-gold sm:inline">{{ $votingOpen ? 'Vote now' : 'About the awards' }} →</span>
        </a>
        <div class="flex shrink-0 items-center gap-4">
            @if($preview)
                <span class="hidden rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-white/80 md:inline" title="Brands, people and numbers on this page are sample content.">Preview · sample data</span>
            @endif
            <a href="{{ $awardRulesUrl }}" class="hidden text-white/70 hover:text-white md:inline">Award rules</a>
            <span class="font-body text-white/70" lang="bn">বাংলা + English</span>
        </div>
    </div>
</div>

{{-- Main navigation --}}
<header id="siteHeader" class="sticky top-0 z-40 border-b border-hair bg-white/90 backdrop-blur-md">
    <div class="{{ $wrap }} flex h-12 items-center justify-between gap-4 sm:h-16 lg:h-[72px]">
        <a href="{{ url('/') }}" class="flex items-center gap-2 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 sm:gap-2.5">
            <x-ui.brand-mark />
            <span class="leading-none">
                <span class="block text-[16px] font-extrabold tracking-tight text-night sm:text-[17px]">MetaSoft BD</span>
                <span class="mt-1 hidden whitespace-nowrap text-[9.5px] font-bold uppercase tracking-[0.16em] text-leaf sm:block lg:hidden xl:block">Brand & Entrepreneur Network</span>
            </span>
        </a>

        <nav class="hidden items-center gap-0.5 lg:flex xl:gap-1" aria-label="Primary">
            @foreach($navLinks as [$label, $href])
                <a href="{{ $href }}" data-nav="{{ ltrim($href, '#') }}"
                   class="relative flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2 py-2 text-[14px] xl:px-2.5 xl:text-[14.5px] font-semibold text-slate2 transition hover:bg-cloud hover:text-night focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf aria-[current=true]:text-night">
                    {{ $label }}
                    @if($label === 'Awards' && $votingOpen)<span class="rounded bg-flag px-1.5 py-px text-[9px] font-extrabold tracking-wide text-white">LIVE</span>@endif
                </a>
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <a href="{{ route('automation') }}"
               title="Business Automation" class="group hidden items-center gap-2 rounded-xl bg-navy p-1.5 text-white transition hover:bg-night focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 lg:flex">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-sky-400 to-leaf"><x-plat.icon name="zap" class="w-4 h-4" /></span>
                <span class="sr-only">Business Automation</span>
            </a>
            <a href="{{ route('central.login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-semibold text-night hover:bg-cloud focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:inline-flex">Login</a>
            <a href="{{ $joinUrl }}"
               class="hidden whitespace-nowrap rounded-xl bg-leaf px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 sm:inline-flex">
                List your brand
            </a>
            <button type="button" id="menuOpen" aria-controls="mobileMenu" aria-expanded="false"
                    class="flex h-9 w-9 items-center justify-center rounded-xl border border-hair text-night sm:h-10 sm:w-10 lg:hidden focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
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
                        @if($label === 'Awards' && $votingOpen)<span class="ml-auto rounded bg-flag px-1.5 py-px text-[10px] font-extrabold text-white">LIVE</span>@endif
                    </a>
                @endforeach
            </nav>
            <a href="{{ route('automation') }}" class="mt-4 flex items-center gap-3 rounded-2xl bg-navy p-4 text-white">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-sky-400 to-leaf"><x-plat.icon name="zap" class="w-5 h-5" /></span>
                <span><span class="block text-sm font-bold">Business Automation</span><span class="text-xs text-white/60">Store, POS, courier & marketing tools</span></span>
            </a>
        </div>
        <div class="grid grid-cols-2 gap-2 border-t border-hair p-4">
            <a href="{{ route('central.login') }}" class="rounded-xl border border-hair py-3 text-center text-sm font-bold">Login</a>
            <a href="{{ $joinUrl }}" class="rounded-xl bg-leaf py-3 text-center text-sm font-bold text-white">List your brand</a>
        </div>
    </div>
</div>

{{--
    Phones and tablets: two compact actions always one tap away. The WhatsApp
    button (icon only, labelled for screen readers) opens the configured
    MetaSoft BD support number (payment.support_whatsapp, the same one the
    footer uses); without one, only the profile button shows. The page
    container's bottom padding (central/home.blade.php) keeps the bar off
    the content.
--}}
<div class="fixed inset-x-0 bottom-0 z-40 border-t border-hair bg-white/95 pb-[max(6px,env(safe-area-inset-bottom))] pl-[max(12px,env(safe-area-inset-left))] pr-[max(12px,env(safe-area-inset-right))] pt-1.5 backdrop-blur-md lg:hidden" data-mobile-cta>
    <div class="mx-auto flex max-w-xl gap-2">
        <a href="{{ $joinUrl }}" class="inline-flex h-10 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-xl bg-night px-3 text-[14px] font-extrabold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            <x-plat.icon name="user-plus" class="w-4 h-4 shrink-0 text-gold" /> <span class="truncate">Create Free Business Profile</span>
        </a>
        @if($whatsapp)
            <a href="{{ $wa('Hi MetaSoft BD, I would like to create a free business profile.') }}" target="_blank" rel="noopener" aria-label="Chat with MetaSoft BD on WhatsApp" title="WhatsApp" class="inline-flex h-10 w-12 shrink-0 items-center justify-center rounded-xl bg-[#25D366] text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#25D366] focus-visible:ring-offset-2">
                @include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-5 h-5'])
            </a>
        @endif
    </div>
</div>
