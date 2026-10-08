@extends('layouts.platform')

@section('title', $brand->name.' — '.($brand->category?->name ?? 'Brand').' from '.$brand->district.' | MetaSoft BD')
@section('meta_description', Str::limit($brand->description ?: $brand->name.' is a '.($brand->category?->name ?? 'Bangladeshi').' brand from '.$brand->district.', listed on MetaSoft BD.', 155))
@section('og_title', $brand->name.' on MetaSoft BD')
@section('og_description', Str::limit($brand->description ?: ($brand->category?->name ?? 'Brand').' · '.$brand->district.', '.$brand->division, 180))
@if($brand->logoUrl())
    @section('og_image', $brand->logoUrl())
@endif

@section('page')
@php
    $wrap = 'mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8';
    [$from, $to] = $brand->gradient();
    $socials = array_filter(['website' => $brand->website, 'facebook' => $brand->facebook, 'instagram' => $brand->instagram, 'tiktok' => $brand->tiktok, 'youtube' => $brand->youtube]);
    $openEntry = $entries->first();
@endphp

<section class="relative overflow-hidden" style="background:linear-gradient(135deg,{{ $from }},{{ $to }})">
    <span class="absolute -right-16 -top-24 h-72 w-72 rounded-full bg-white/10" aria-hidden="true"></span>
    <span class="absolute left-1/3 top-10 h-48 w-48 rounded-full bg-white/[0.06]" aria-hidden="true"></span>
    <div class="{{ $wrap }} relative h-32 sm:h-40">
        @if($brand->isSponsoredNow())
            <span class="absolute right-4 top-4 sm:right-6"><x-plat.badge type="sponsored" label="Sponsored" /></span>
        @endif
    </div>
</section>

<section class="{{ $wrap }}">
    <div class="relative -mt-14 rounded-[24px] border border-hair bg-white p-5 shadow-[0_12px_40px_-20px_rgba(10,20,40,0.25)] sm:p-8">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-end gap-4">
                <x-plat.logo :initials="$brand->initials()" :from="$from" :to="$to" :size="96" ring :src="$brand->logoUrl()" :alt="$brand->name.' logo'" class="-mt-16 shadow-lg sm:-mt-20" />
                <div class="min-w-0 pb-1">
                    <h1 class="flex items-center gap-2 text-[26px] font-extrabold leading-tight tracking-tight sm:text-[32px]">
                        <span class="break-words">{{ $brand->name }}</span>
                        @if($brand->is_verified)<x-plat.verified size="w-6 h-6 sm:w-7 sm:h-7" />@endif
                    </h1>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[14px] text-slate2">
                        <span class="font-semibold text-night">{{ $brand->category?->name ?? 'Brand' }}</span>
                        @if($brand->sub_category)<span aria-hidden="true">·</span> {{ $brand->sub_category }}@endif
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex items-center gap-1"><x-plat.icon name="map-pin" class="w-3.5 h-3.5" /> {{ $brand->district }}, {{ $brand->division }}</span>
                        @if($brand->founded_year)<span aria-hidden="true">·</span> Since {{ $brand->founded_year }}@endif
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($openEntry)
                    <a href="{{ $brand->voteUrl() }}" class="inline-flex items-center gap-2 rounded-xl bg-flag px-5 py-3 text-sm font-bold text-white shadow-sm hover:brightness-95">
                        <x-plat.icon name="vote" class="w-4 h-4" /> Vote for {{ Str::limit($brand->name, 22) }}
                    </a>
                @endif
                <button type="button" data-share data-share-url="{{ $brand->profileUrl() }}" data-share-title="{{ $brand->name }}" data-share-text="Discover {{ $brand->name }} on MetaSoft BD"
                        class="inline-flex items-center gap-2 rounded-xl border border-hair px-4 py-3 text-sm font-bold hover:border-leaf">
                    <x-plat.icon name="share" class="w-4 h-4" /> Share
                </button>
            </div>
        </div>

        @if($badges || $brand->is_verified)
            <div class="mt-5 flex flex-wrap gap-2 border-t border-hair pt-5">
                @if($brand->is_verified)<x-plat.badge type="verified" label="MetaSoft BD Verified" />@endif
                @foreach($badges as $b)
                    <x-plat.badge :type="$b['type']" :label="$b['label']" />
                @endforeach
            </div>
        @endif
    </div>
</section>

<section class="{{ $wrap }} grid gap-6 py-8 lg:grid-cols-[1fr_340px]">
    <div class="space-y-6">
        <article class="rounded-[20px] border border-hair bg-white p-5 sm:p-7">
            <h2 class="text-lg font-extrabold">About {{ $brand->name }}</h2>
            <div class="mt-3 whitespace-pre-line text-[15px] leading-relaxed text-slate2">{{ $brand->description ?: $brand->name.' is a '.($brand->category?->name ?? 'Bangladeshi').' brand from '.$brand->district.', '.$brand->division.'.' }}</div>
            @if($brand->products_info)
                <h3 class="mt-6 text-[15px] font-extrabold">Products & services</h3>
                <div class="mt-2 whitespace-pre-line text-[15px] leading-relaxed text-slate2">{{ $brand->products_info }}</div>
            @endif
        </article>

        @if($brand->gallery)
            <article class="rounded-[20px] border border-hair bg-white p-5 sm:p-7">
                <h2 class="text-lg font-extrabold">Gallery</h2>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach($brand->galleryUrls() as $i => $src)
                        <a href="{{ $src }}" target="_blank" rel="noopener" class="block aspect-square overflow-hidden rounded-xl bg-cloud">
                            <img src="{{ $src }}" alt="{{ $brand->name }} photo {{ $i + 1 }}" loading="lazy" class="h-full w-full object-cover transition hover:scale-105">
                        </a>
                    @endforeach
                </div>
            </article>
        @endif

        @if($recognitions->isNotEmpty() || $finalists->isNotEmpty())
            <article class="rounded-[20px] border border-hair bg-white p-5 sm:p-7">
                <h2 class="text-lg font-extrabold">Recognition</h2>
                <ul class="mt-4 divide-y divide-hair">
                    @foreach($recognitions as $r)
                        <li class="flex items-center gap-3 py-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gold/25 text-golddk"><x-plat.icon name="trophy" class="w-5 h-5" /></span>
                            <div class="min-w-0">
                                <p class="font-bold">{{ $r->label() }}@if($r->category) · {{ $r->category->name }}@endif</p>
                                <p class="text-sm text-slate2">{{ $r->award?->title }}</p>
                            </div>
                        </li>
                    @endforeach
                    @foreach($finalists as $n)
                        <li class="flex items-center gap-3 py-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-mint text-leafdk"><x-plat.icon name="award" class="w-5 h-5" /></span>
                            <div class="min-w-0">
                                <p class="font-bold">{{ $n->statusLabel() }} · {{ $n->category?->name }}</p>
                                <p class="text-sm text-slate2">{{ $n->award?->title }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </article>
        @endif
    </div>

    <aside class="space-y-6">
        @if($entries->isNotEmpty())
            <div class="rounded-[20px] bg-[linear-gradient(135deg,#0A1428,#0E2350)] p-5 text-white">
                <p class="text-[11px] font-extrabold uppercase tracking-[0.16em] text-gold">Voting open</p>
                @foreach($entries as $e)
                    <p class="mt-2 font-bold">{{ $e->campaign->title }}</p>
                    <p class="text-sm text-white/70">{{ $e->category->name }}@if($e->campaign->ends_at) · ends {{ $e->campaign->ends_at->format('j M') }}@endif</p>
                @endforeach
                <a href="{{ $brand->voteUrl() }}" class="mt-4 flex items-center justify-center gap-2 rounded-xl bg-gold py-3 text-sm font-bold text-night hover:brightness-105"><x-plat.icon name="vote" class="w-4 h-4" /> Vote now</a>
            </div>
        @endif

        <div class="rounded-[20px] border border-hair bg-white p-5">
            <h2 class="text-[15px] font-extrabold">Founder</h2>
            <p class="mt-2 flex items-center gap-2 font-semibold"><x-plat.icon name="users" class="w-4 h-4 text-slate2" /> {{ $brand->founder_name }}</p>
            @if($socials)
                <h2 class="mt-5 text-[15px] font-extrabold">Find {{ Str::limit($brand->name, 24) }} online</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach($socials as $platform => $link)
                        <a href="{{ $link }}" target="_blank" rel="noopener nofollow ugc" class="inline-flex items-center gap-2 rounded-xl border border-hair px-3 py-2 text-sm font-semibold hover:border-leaf">
                            @include('partials.icon', ['platform' => $platform, 'class' => 'w-4 h-4']) {{ ucfirst($platform) }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-[20px] border border-hair bg-white p-5">
            <h2 class="text-[15px] font-extrabold">Share this brand</h2>
            <x-plat.share-kit class="mt-3" compact :url="$brand->profileUrl()" :title="$brand->name" :text="'Discover '.$brand->name.' on MetaSoft BD:'" />
        </div>

        <p class="px-1 text-xs leading-relaxed text-slate2">
            @if($brand->is_verified)
                <x-plat.verified class="inline -mt-0.5" /> MetaSoft BD has verified this brand’s identity and contact details.
            @else
                This brand was reviewed before listing. Verification is a separate check carried out by MetaSoft BD.
            @endif
            @if($brand->isSponsoredNow()) “Sponsored” means paid placement — it is never an award or a verification.@endif
        </p>
    </aside>
</section>

@if($related->isNotEmpty())
    <section class="{{ $wrap }} pb-12">
        <h2 class="mb-4 text-lg font-extrabold">More {{ $brand->category?->name ?? '' }} brands</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($related as $r)
                <x-plat.brand-card :brand="$r->toCard()" />
            @endforeach
        </div>
    </section>
@endif
@endsection
