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
    // Anchors of sections that are not on the page, so no menu links to them.
    // Stories and events have no real content source yet, so they never render
    // (their partials stay for when published content exists); sample-only
    // sections render only while sample content is switched on.
    $hiddenAnchors = array_keys(array_filter([
        '#stories' => true,
        '#events' => true,
        '#trending' => ! $samples,
        '#entrepreneurs' => ! $samples,
        '#sponsors' => ! $home['show_sponsors'],
    ]));
    $wrap = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8';
@endphp

@section('content')
<div id="top" class="plat-theme font-plat bg-white text-night pb-[76px] lg:pb-0">

    @include('central.home.header')

    <main id="main-content" tabindex="-1" class="outline-none">
        @include('central.home.hero')
        @include('central.home.our-brands')

        {{-- Search / "browse all" results; the search form jumps straight to #results. --}}
        @if($results !== null)
            @include('central.home.results')
        @endif

        @include('central.home.how-it-works')
        @include('central.home.vote-cta')
        @include('central.home.awards')
        @include('central.home.voting')
        {{-- What Verified / Editor’s Pick / Sponsored / votes mean (#verified, #sponsors). --}}
        @include('central.home.trust')
        @include('central.home.brands')
        {{-- Sample-only sections: shown only while sample content is on, always labelled. --}}
        @if($samples)
            @include('central.home.trending')
            @include('central.home.entrepreneurs')
        @endif
        @include('central.home.recognition')
        @include('central.home.join')
        @include('central.home.automation')
    </main>

    @include('central.home.footer')
    @include('central.home.overlays')
</div>
@endsection
