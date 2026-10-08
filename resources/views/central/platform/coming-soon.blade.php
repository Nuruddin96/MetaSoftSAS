{{-- Shown by EnsurePlatformReady until database/sql/chunk64.sql is imported. Deliberately has no route() links into the platform. --}}
@extends('layouts.central')

@section('title', 'Coming soon — MetaSoft BD')

@push('head')
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
@endpush

@section('content')
<main id="main-content" class="flex min-h-screen items-center justify-center bg-cloud px-4 font-plat text-night">
    <div class="w-full max-w-md rounded-[24px] border border-hair bg-white p-8 text-center shadow-sm">
        <x-ui.brand-mark />
        <h1 class="mt-5 text-2xl font-extrabold tracking-tight">This part of MetaSoft BD is almost ready</h1>
        <p class="mt-3 text-[15px] leading-relaxed text-slate2">Brand listings, voting and brand dashboards are being set up. Please check back shortly.</p>
        <a href="{{ url('/') }}" class="mt-6 inline-flex rounded-xl bg-leaf px-5 py-3 text-sm font-bold text-white hover:bg-leafdk">Back to homepage</a>
    </div>
</main>
@endsection
