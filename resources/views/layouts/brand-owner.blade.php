{{--
    Brand owner dashboard shell (auth:brand_owner). Desktop: left sidebar.
    Phone: slim top bar + slide-over menu (platform.js #ownerMenu) and a
    bottom tab bar for the everyday tasks. Pages yield 'page'.
--}}
@extends('layouts.central')

@push('head')
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    @vite('resources/js/platform.js')
@endpush

@section('content')
@php
    $owner = auth('brand_owner')->user();
    $ownerBrand = $owner->brand;
    $unread = \App\Models\PlatformNotification::forOwner($owner->id)->unread()->count();
    $nav = [
        ['owner.dashboard', 'Dashboard', 'home'],
        ['owner.brand.edit', 'My brand', 'store'],
        ['owner.voting', 'Voting', 'vote'],
        ['owner.awards', 'Awards', 'trophy'],
        ['owner.notifications', 'Notifications', 'megaphone'],
        ['owner.account', 'Account', 'lock'],
    ];
    $active = fn ($r) => request()->routeIs($r) || ($r === 'owner.brand.edit' && request()->routeIs('owner.brand.*'));
    $navLink = function ($r, $label, $icon) use ($active, $unread) {
        $on = $active($r);
        $badge = $r === 'owner.notifications' && $unread ? '<span class="ml-auto rounded-full bg-flag px-2 py-0.5 text-[11px] font-bold text-white">'.$unread.'</span>' : '';

        return [$on, $badge];
    };
@endphp
<div class="min-h-screen bg-cloud font-plat text-night lg:flex">
    {{-- Desktop sidebar --}}
    <aside class="hidden w-64 shrink-0 flex-col border-r border-hair bg-white lg:flex">
        <a href="{{ url('/') }}" class="flex items-center gap-2.5 px-5 py-5">
            <x-ui.brand-mark />
            <span class="leading-none"><span class="block text-[16px] font-extrabold">MetaSoft BD</span><span class="mt-1 block text-[10px] font-bold uppercase tracking-[0.14em] text-leaf">Brand dashboard</span></span>
        </a>
        <nav class="flex-1 space-y-0.5 px-3" aria-label="Dashboard">
            @foreach($nav as [$r, $label, $icon])
                @php [$on, $badge] = $navLink($r, $label, $icon); @endphp
                <a href="{{ route($r) }}" @if($on) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[14.5px] font-semibold {{ $on ? 'bg-mint text-leafdk' : 'text-slate2 hover:bg-cloud hover:text-night' }}">
                    <x-plat.icon :name="$icon" class="w-[18px] h-[18px]" /> {{ $label }} {!! $badge !!}
                </a>
            @endforeach
        </nav>
        <div class="border-t border-hair p-4">
            @if($ownerBrand?->isPublished())
                <a href="{{ $ownerBrand->profileUrl() }}" target="_blank" class="mb-3 flex items-center gap-2 text-sm font-semibold text-leaf hover:underline"><x-plat.icon name="arrow-up-right" class="w-4 h-4" /> View public profile</a>
            @endif
            <p class="truncate text-sm font-bold">{{ $owner->name }}</p>
            <p class="truncate text-xs text-slate2">{{ $owner->email }}</p>
            <form method="POST" action="{{ route('owner.logout') }}" class="mt-3">@csrf
                <button class="text-sm font-semibold text-slate2 hover:text-night">Log out</button>
            </form>
        </div>
    </aside>

    {{-- Phone top bar --}}
    <header class="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-hair bg-white/95 px-4 backdrop-blur lg:hidden">
        <a href="{{ route('owner.dashboard') }}" class="flex items-center gap-2"><x-ui.brand-mark size="sm" /><span class="text-[15px] font-extrabold">Brand dashboard</span></a>
        <div class="flex items-center gap-1">
            <a href="{{ route('owner.notifications') }}" class="relative rounded-lg p-2" aria-label="Notifications">
                <x-plat.icon name="megaphone" class="w-5 h-5" />
                @if($unread)<span class="absolute right-1 top-1 h-2.5 w-2.5 rounded-full bg-flag ring-2 ring-white"></span>@endif
            </a>
            <button type="button" id="ownerMenuOpen" class="rounded-lg p-2" aria-label="Open menu"><x-plat.icon name="menu" class="w-5 h-5" /></button>
        </div>
    </header>

    {{-- Phone slide-over menu --}}
    <div id="ownerMenu" class="fixed inset-0 z-50 hidden lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="absolute inset-0 bg-night/50" data-close-owner-menu></div>
        <div class="absolute inset-y-0 right-0 flex w-72 max-w-[85%] flex-col bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-hair px-4 py-3">
                <div class="min-w-0"><p class="truncate font-bold">{{ $owner->name }}</p><p class="truncate text-xs text-slate2">{{ $ownerBrand?->name }}</p></div>
                <button type="button" class="rounded-lg p-2" data-close-owner-menu aria-label="Close menu"><x-plat.icon name="x" class="w-5 h-5" /></button>
            </div>
            <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
                @foreach($nav as [$r, $label, $icon])
                    @php [$on, $badge] = $navLink($r, $label, $icon); @endphp
                    <a href="{{ route($r) }}" class="flex items-center gap-3 rounded-xl px-3 py-3 font-semibold {{ $on ? 'bg-mint text-leafdk' : 'text-night hover:bg-cloud' }}">
                        <x-plat.icon :name="$icon" class="w-5 h-5" /> {{ $label }} {!! $badge !!}
                    </a>
                @endforeach
                @if($ownerBrand?->isPublished())
                    <a href="{{ $ownerBrand->profileUrl() }}" target="_blank" class="flex items-center gap-3 rounded-xl px-3 py-3 font-semibold text-leaf"><x-plat.icon name="arrow-up-right" class="w-5 h-5" /> View public profile</a>
                @endif
                <a href="{{ url('/') }}" class="flex items-center gap-3 rounded-xl px-3 py-3 font-semibold text-slate2"><x-plat.icon name="globe" class="w-5 h-5" /> MetaSoft BD home</a>
            </nav>
            <form method="POST" action="{{ route('owner.logout') }}" class="border-t border-hair p-4">@csrf
                <button class="w-full rounded-xl border border-hair py-3 text-sm font-bold">Log out</button>
            </form>
        </div>
    </div>

    <main id="main-content" tabindex="-1" class="min-w-0 flex-1 pb-24 outline-none lg:pb-10">
        <div class="mx-auto w-full max-w-5xl px-4 py-5 sm:px-6 lg:px-8 lg:py-8">
            <x-plat.flash class="mb-5" />
            @yield('page')
        </div>
    </main>

    {{-- Phone bottom tabs --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-hair bg-white pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Quick">
        @foreach(array_slice($nav, 0, 4) as [$r, $label, $icon])
            @php $on = $active($r); @endphp
            <a href="{{ route($r) }}" class="flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-bold {{ $on ? 'text-leaf' : 'text-slate2' }}">
                <x-plat.icon :name="$icon" class="w-5 h-5" /> {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
@endsection
