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

{{-- Premium header: the brand's cover (uploaded cover → first gallery photo → brand gradient) fading into navy, the logo on a white tile, and the name with its "✓ Verified" pill (only when verified). --}}
<section class="relative isolate overflow-hidden bg-night text-white" data-brand-hero>
    @if($cover = $brand->coverUrl())
        <img src="{{ $cover }}" alt="" class="absolute inset-0 -z-20 h-full w-full object-cover" fetchpriority="high" data-brand-cover>
    @else
        <div class="absolute inset-0 -z-20" style="background:linear-gradient(135deg,{{ $from }},{{ $to }})"></div>
    @endif
    <div class="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgba(11,37,69,0.10)_0%,rgba(11,37,69,0.45)_45%,rgba(11,37,69,0.92)_78%,#0B2545_100%)]" aria-hidden="true"></div>

    <div class="{{ $wrap }} relative">
        <div class="flex items-start justify-between gap-3 pt-4">
            <a href="{{ route('brands.index') }}" class="inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1.5 text-[13px] font-bold text-night shadow-sm hover:bg-white">
                <x-plat.icon name="chevron-right" class="w-3.5 h-3.5 rotate-180" /> All brands
            </a>
            @if($brand->isSponsoredNow())<x-plat.badge type="sponsored" label="Sponsored" />@endif
        </div>

        <div class="flex flex-col gap-4 pb-6 pt-24 sm:flex-row sm:items-end sm:justify-between sm:pb-8 sm:pt-40">
            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:gap-5">
                <span class="inline-flex w-fit shrink-0 rounded-[22px] bg-white p-1.5 shadow-[0_18px_40px_-18px_rgba(0,0,0,0.6)]">
                    <x-plat.logo :initials="$brand->initials()" :from="$from" :to="$to" :size="96" :src="$brand->logoUrl()" :alt="$brand->name.' logo'" />
                </span>
                <div class="min-w-0 sm:pb-1">
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                        <h1 class="break-words text-[26px] font-extrabold leading-tight tracking-tight sm:text-[34px]">{{ $brand->name }}</h1>
                        @if($brand->is_verified)<x-plat.verified-pill class="mt-1" />@endif
                    </div>
                    <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[14px] text-white/75">
                        <span class="rounded-full bg-white/12 px-2 py-0.5 text-[12px] font-semibold text-white ring-1 ring-white/15">{{ $brand->category?->name ?? 'Brand' }}</span>
                        @if($brand->sub_category)<span>{{ $brand->sub_category }}</span><span aria-hidden="true">·</span>@endif
                        <span class="inline-flex items-center gap-1"><x-plat.icon name="map-pin" class="w-3.5 h-3.5" /> {{ $brand->district }}, {{ $brand->division }}</span>
                        @if($brand->founded_year)<span aria-hidden="true">·</span> Since {{ $brand->founded_year }}@endif
                    </p>
                    @if($badges)
                        <div class="mt-2.5 flex flex-wrap gap-2">
                            @foreach($badges as $b)<x-plat.badge :type="$b['type']" :label="$b['label']" />@endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                @if($openEntry)
                    <a href="{{ $brand->voteUrl() }}" class="inline-flex items-center gap-2 rounded-xl bg-flag px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:brightness-95">
                        <x-plat.icon name="vote" class="w-4 h-4" /> Vote for {{ Str::limit($brand->name, 22) }}
                    </a>
                @endif
                <button type="button" data-share data-share-url="{{ $brand->profileUrl() }}" data-share-title="{{ $brand->name }}" data-share-text="Discover {{ $brand->name }} on MetaSoft BD"
                        class="inline-flex items-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-bold text-white backdrop-blur hover:bg-white/20">
                    <x-plat.icon name="share" class="w-4 h-4" /> Share
                </button>
            </div>
        </div>
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
            <div class="rounded-[20px] bg-[linear-gradient(135deg,#052B4C,#073B66)] p-5 text-white">
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

        <div class="px-1 text-xs leading-relaxed text-slate2" data-verification-note>
            @if($brand->is_verified)
                <p><x-plat.verified class="inline -mt-0.5" /> <strong class="text-night">MetaSoft BD Verified</strong>@if($brand->verified_at) since {{ $brand->verified_at->format('F Y') }}@endif.
                    @if($evidence = $brand->verificationEvidence())
                        Our team reviewed:</p>
                        <ul class="mt-1 list-inside list-disc">@foreach($evidence as $item)<li>{{ $item }}</li>@endforeach</ul>
                    @else
                        Granted after a MetaSoft BD admin review; the specific evidence reviewed was not recorded.</p>
                    @endif
                <p class="mt-1">Verification is not an award, a vote result or a paid placement.</p>
            @else
                <p>This brand was reviewed before listing. It does not carry the Verified badge — verification is a separate check by MetaSoft BD.</p>
            @endif
            @if($brand->isSponsoredNow())<p class="mt-1">“Sponsored” means paid placement — it is never an award or a verification.</p>@endif
            <p class="mt-1"><a href="{{ route('landing') }}#verified" class="font-bold text-leaf hover:underline">What the Verified badge means</a></p>
        </div>
    </aside>
</section>

@if($related->isNotEmpty())
    <section class="{{ $wrap }} pb-12">
        <h2 class="mb-4 text-lg font-extrabold">More {{ $brand->category?->name ?? '' }} brands</h2>
        <div class="brand-grid">
            @foreach($related as $r)
                <x-plat.brand-card :brand="$r->toCard()" />
            @endforeach
        </div>
    </section>
@endif
@endsection

@section('footer')
    <x-plat.site-footer />
@endsection
