{{--
    Profile page for a homepage sample (showcase) brand — App\Support\Home\Showcase.
    Same layout as a real brand page (brand.blade.php), but only the fields a
    sample brand actually has, a clear "sample profile" notice and noindex.
--}}
@extends('layouts.platform')

@section('title', $brand['name'].' — '.$brand['category'].' | MetaSoft BD')
@section('meta_description', Str::limit($brand['description'], 155))
@section('robots', 'noindex, follow')
@section('og_title', $brand['name'].' on MetaSoft BD')
@section('og_description', Str::limit($brand['description'], 180))
@if($brand['logo'])
    @section('og_image', $brand['logo'])
@endif

@section('page')
@php
    $wrap = 'mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8';
    $url = url($brand['url']);
    $place = implode(', ', array_unique([$brand['district'], $brand['division']]));
@endphp

<section class="relative overflow-hidden" style="background:linear-gradient(135deg,{{ $brand['from'] }},{{ $brand['to'] }})">
    @if($brand['cover'])
        <img src="{{ $brand['cover'] }}" alt="" class="absolute inset-0 h-full w-full object-cover" decoding="async">
        <span class="absolute inset-0 bg-night/20" aria-hidden="true"></span>
    @endif
    <div class="{{ $wrap }} relative h-32 sm:h-48">
        <a href="{{ url('/') }}?browse=brands#results" class="absolute left-4 top-4 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1.5 text-[13px] font-bold text-night shadow-sm hover:bg-white sm:left-6">
            <x-plat.icon name="chevron-right" class="w-3.5 h-3.5 rotate-180" /> All brands
        </a>
    </div>
</section>

<section class="{{ $wrap }}">
    <div class="relative -mt-14 rounded-[24px] border border-hair bg-white p-5 shadow-[0_12px_40px_-20px_rgba(10,20,40,0.25)] sm:p-8">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-end gap-4">
                <x-plat.logo :initials="$brand['initials']" :from="$brand['from']" :to="$brand['to']" :size="96" ring :src="$brand['logo']" :alt="$brand['name'].' logo'" class="-mt-16 shadow-lg sm:-mt-20" />
                <div class="min-w-0 pb-1">
                    <h1 class="flex items-center gap-2 text-[24px] font-extrabold leading-tight tracking-tight sm:text-[30px]">
                        <span class="break-words">{{ $brand['name'] }}</span>
                        @if($brand['verified'])<x-plat.verified size="w-5 h-5 sm:w-6 sm:h-6" />@endif
                    </h1>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[14px] text-slate2">
                        <span class="font-semibold text-night">{{ $brand['category'] }}</span>
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex items-center gap-1"><x-plat.icon name="map-pin" class="w-3.5 h-3.5" /> {{ $place }}</span>
                    </p>
                </div>
            </div>
            <button type="button" data-share data-share-url="{{ $url }}" data-share-title="{{ $brand['name'] }}" data-share-text="Discover {{ $brand['name'] }} on MetaSoft BD"
                    class="inline-flex items-center justify-center gap-2 self-start rounded-xl border border-hair px-4 py-3 text-sm font-bold hover:border-leaf sm:self-auto">
                <x-plat.icon name="share" class="w-4 h-4" /> Share
            </button>
        </div>
        <p class="mt-5 flex items-start gap-2 rounded-xl bg-cloud px-3.5 py-2.5 text-[13px] text-slate2">
            <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0" /> Sample profile shown while the directory grows. Not verified by MetaSoft BD.
        </p>
    </div>
</section>

<section class="{{ $wrap }} grid gap-6 py-8 lg:grid-cols-[1fr_340px]">
    <article class="rounded-[20px] border border-hair bg-white p-5 sm:p-7">
        <h2 class="text-lg font-extrabold">About {{ $brand['name'] }}</h2>
        <p class="mt-3 text-[15px] leading-relaxed text-slate2">{{ $brand['description'] }}</p>
        @if($brand['founder'])
            <h3 class="mt-6 text-[15px] font-extrabold">Founder</h3>
            <p class="mt-2 text-[15px] text-slate2">{{ $brand['founder'] }}</p>
        @endif
    </article>

    <aside class="rounded-[20px] border border-hair bg-white p-5">
        <h2 class="text-[15px] font-extrabold">Share this brand</h2>
        <x-plat.share-kit class="mt-3" compact :url="$url" :title="$brand['name']" :text="'Discover '.$brand['name'].' on MetaSoft BD:'" />
    </aside>
</section>

@if($related)
    <section class="{{ $wrap }} pb-12">
        <h2 class="mb-4 text-lg font-extrabold">More {{ $brand['category'] }} brands</h2>
        <div class="brand-grid">
            @foreach($related as $r)
                <x-plat.brand-card :brand="$r + ['sample' => true]" />
            @endforeach
        </div>
    </section>
@endif
@endsection
