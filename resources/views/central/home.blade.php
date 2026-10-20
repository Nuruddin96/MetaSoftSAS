@extends('layouts.central')

@section('title', 'MetaSoft BD — Bangladesh’s Entrepreneur & Brand Growth Network')
@section('meta_description', 'Discover Bangladeshi brands and entrepreneurs, vote in transparent national awards, and build your brand’s permanent digital identity. বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম।')

@push('head')
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,500&display=swap" rel="stylesheet">
    <meta property="og:title" content="MetaSoft BD — Discover Brands • Celebrate Entrepreneurs • Vote • Grow">
    <meta property="og:description" content="Bangladesh’s Entrepreneur & Brand Growth Network.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    @vite('resources/js/home.js')
@endpush

@php
    $wa = fn (string $text) => 'https://wa.me/'.$whatsapp.'?text='.rawurlencode($text);
    // Self-service registration (BrandOwner\RegisterController) — replaces sending details over WhatsApp.
    $joinUrl = route('owner.register');
    $wrap = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8';
    // Concept C section headings: amber-ink kicker over a navy title.
    $eyebrow = 'flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-[0.16em] text-golddk';
    $rule = 'h-px w-6 bg-golddk';
    $h2 = 'mt-1.5 text-[22px] font-extrabold leading-tight tracking-tight text-night sm:text-[28px]';
@endphp

@section('content')
{{--
    Concept C homepage (approved design: design-preview concept C). Order:
    hero → photo strip → Brands building Bangladesh → Well-known brands
    (discovery listings) → Verified badge →
    Our journey → Consultancy & networking → Awards → Company credentials →
    Join → footer. Real data throughout; sections whose material isn't
    supplied yet show an honest fallback (HomeMedia).
--}}
<div id="top" class="plat-theme font-plat bg-white text-night pb-[calc(58px+env(safe-area-inset-bottom))] lg:pb-0">

    @include('central.home.header')

    <main id="main-content" tabindex="-1" class="outline-none">
        @include('central.home.hero')
        @include('central.home.moments')

        {{-- Search / "browse all" results; the hero search jumps straight to #results. --}}
        @if($results !== null)
            @include('central.home.results')
        @endif

        @include('central.home.our-brands')
        @include('central.home.directory')
        @include('central.home.verified')
        @include('central.home.journey')
        @include('central.home.consultancy')
        @include('central.home.awards')
        @include('central.home.credentials')
        @include('central.home.join')
    </main>

    @include('central.home.footer')
    @include('central.home.overlays')
</div>
@endsection
