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
    // Anchors of sections hidden in Super Admin → Homepage, so no menu links to them.
    $hiddenAnchors = array_keys(array_filter(['#stories' => ! $home['show_stories'], '#events' => ! $home['show_events'], '#sponsors' => ! $home['show_sponsors']]));
    $wrap = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8';
@endphp

@section('content')
<div id="top" class="font-plat bg-white text-night pb-[76px] lg:pb-0">

    @include('central.home.header')

    <main id="main-content" tabindex="-1" class="outline-none">
        @include('central.home.hero')

        @if($results !== null)
            @include('central.home.results')
        @endif

        @include('central.home.trust')
        @include('central.home.awards')
        @include('central.home.voting')
        @include('central.home.trending')
        @include('central.home.brands')
        @include('central.home.entrepreneurs')
        @include('central.home.recognition')
        @include('central.home.join')
        {{-- Sample-content sections; each can be hidden in Super Admin → Homepage. --}}
        @if($home['show_stories'])@include('central.home.stories')@endif
        @if($home['show_events'])@include('central.home.events')@endif
        @include('central.home.automation')
        @if($home['show_sponsors'])@include('central.home.sponsors')@endif
    </main>

    @include('central.home.footer')
    @include('central.home.overlays')
</div>
@endsection
