{{--
    Public recognition-platform pages (/brands, /brand/{slug}, /vote/{slug},
    /voting/{slug}, /awards/{slug}, /list-your-brand, brand-owner login).
    Same central layout + Plus Jakarta Sans palette as the homepage, with a
    slim header that links back to the homepage sections.
--}}
@extends('layouts.central')

@push('head')
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @hasSection('og_title')
        <meta property="og:site_name" content="MetaSoft BD">
        <meta property="og:title" content="@yield('og_title')">
        <meta property="og:description" content="@yield('og_description', 'Bangladesh’s Entrepreneur & Brand Growth Network.')">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="@hasSection('og_image')@yield('og_image')@else{{ asset('images/icons/icon-512.png') }}@endif">
        <meta name="twitter:card" content="summary">
    @endif
    @vite('resources/js/platform.js')
@endpush

@section('content')
@php $wrap = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8'; @endphp
<div class="flex min-h-screen flex-col bg-cloud font-plat text-night">
    <header class="sticky top-0 z-40 border-b border-hair bg-white/90 backdrop-blur-md">
        <div class="{{ $wrap }} flex h-16 items-center justify-between gap-3">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                <x-ui.brand-mark />
                <span class="leading-none">
                    <span class="block text-[17px] font-extrabold tracking-tight">MetaSoft BD</span>
                    <span class="mt-1 hidden text-[9.5px] font-bold uppercase tracking-[0.16em] text-leaf sm:block">Brand & Entrepreneur Network</span>
                </span>
            </a>
            <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
                <a href="{{ route('brands.index') }}" class="rounded-lg px-3 py-2 text-[14.5px] font-semibold text-slate2 hover:bg-cloud hover:text-night">Brands</a>
                <a href="{{ url('/') }}#awards" class="rounded-lg px-3 py-2 text-[14.5px] font-semibold text-slate2 hover:bg-cloud hover:text-night">Awards</a>
                <a href="{{ url('/') }}#voting" class="rounded-lg px-3 py-2 text-[14.5px] font-semibold text-slate2 hover:bg-cloud hover:text-night">Voting</a>
            </nav>
            <div class="flex items-center gap-2">
                @auth('brand_owner')
                    <a href="{{ route('owner.dashboard') }}" class="whitespace-nowrap rounded-xl bg-night px-4 py-2.5 text-sm font-bold text-white hover:bg-navy">My dashboard</a>
                @else
                    <a href="{{ route('owner.login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-semibold hover:bg-cloud sm:inline-flex">Brand login</a>
                    <a href="{{ route('owner.register') }}" class="whitespace-nowrap rounded-xl bg-leaf px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-leafdk">List your brand</a>
                @endauth
            </div>
        </div>
    </header>

    <main id="main-content" tabindex="-1" class="flex-1 outline-none">
        @if(session('success') || session('error'))
            <div class="{{ $wrap }} pt-5"><x-plat.flash /></div>
        @endif
        @yield('page')
    </main>

    <footer class="border-t border-hair bg-white">
        <div class="{{ $wrap }} flex flex-col gap-4 py-8 text-sm text-slate2 sm:flex-row sm:items-center sm:justify-between">
            <p class="flex items-center gap-2"><x-ui.brand-mark size="sm" /> <span><b class="text-night">MetaSoft BD</b> · Bangladesh’s Entrepreneur & Brand Growth Network</span></p>
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Footer">
                <a href="{{ route('brands.index') }}" class="hover:text-night">Brand directory</a>
                <a href="{{ route('owner.register') }}" class="hover:text-night">List your brand</a>
                <a href="{{ url('/') }}#recognition" class="hover:text-night">Award rules</a>
                <a href="{{ route('automation') }}" class="hover:text-night">Business Automation</a>
            </nav>
        </div>
    </footer>
</div>
@endsection
