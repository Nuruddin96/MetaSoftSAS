{{--
    Discovery listing page (/directory/{slug}) — a well-known brand the
    MetaSoft BD team listed from its official public sources. It is not a
    registered profile: no Verified badge, no voting, no contact details,
    no logo copied from the brand, and a plain notice that the business has
    not joined or been verified by MetaSoft BD. Links to the official
    sources are rel="nofollow noopener".
--}}
@extends('layouts.platform')

@section('title', $listing->name.' — '.($listing->category?->name ?? 'Brand').' | MetaSoft BD directory')
@section('meta_description', Str::limit($listing->description ?? $listing->name.' in the MetaSoft BD brand directory.', 155))
@section('robots', 'noindex, follow')
@section('og_title', $listing->name.' — brand directory')

@section('page')
@php
    $wrap = 'mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8';
    $ext = 'inline-flex items-center gap-1.5 rounded-xl border border-hair px-4 py-2.5 text-sm font-bold hover:border-leaf';
@endphp

<section class="border-b border-hair bg-white">
    <div class="{{ $wrap }} py-8 sm:py-10">
        <a href="{{ route('brands.index') }}#listings" class="inline-flex items-center gap-1.5 text-[13px] font-bold text-slate2 hover:text-night">
            <x-plat.icon name="chevron-right" class="w-3.5 h-3.5 rotate-180" /> Brand directory
        </a>
        <div class="mt-4 flex items-center gap-4">
            <x-plat.logo :initials="$listing->initials()" from="#475569" to="#1E293B" :size="72" />
            <div class="min-w-0">
                <h1 class="break-words text-[26px] font-extrabold leading-tight tracking-tight sm:text-[32px]">{{ $listing->name }}</h1>
                <p class="mt-1 text-[14px] text-slate2">
                    @if($listing->category)<a href="{{ route('brands.index', ['category' => $listing->category->slug]) }}#listings" class="font-semibold text-night hover:text-leaf">{{ $listing->category->name }}</a>@else Brand @endif
                    @if($listing->ownershipLabel()) <span aria-hidden="true">·</span> {{ $listing->ownershipLabel() }}@endif
                </p>
            </div>
        </div>

        <p class="mt-5 flex items-start gap-2 rounded-xl bg-cloud px-3.5 py-2.5 text-[13px] text-slate2">
            <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0" />
            <span>Public directory listing compiled by MetaSoft BD from the brand’s official website for discovery. This business has not joined, endorsed or partnered with MetaSoft BD, and has not been verified by MetaSoft BD.</span>
        </p>
    </div>
</section>

<section class="{{ $wrap }} grid gap-6 py-8 sm:py-10 lg:grid-cols-[1fr_320px]">
    <div class="space-y-6">
        @if($listing->description)
            <div>
                <h2 class="text-lg font-extrabold">About</h2>
                <p class="mt-2 text-[15px] leading-relaxed text-slate2">{{ $listing->description }}</p>
            </div>
        @endif

        @if($listing->website || $listing->facebook)
            <div>
                <h2 class="text-lg font-extrabold">Official links</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if($listing->website)<a href="{{ $listing->website }}" target="_blank" rel="nofollow noopener noreferrer" class="{{ $ext }}"><x-plat.icon name="globe" class="w-4 h-4" /> {{ $listing->official_domain }} <x-plat.icon name="arrow-up-right" class="w-3.5 h-3.5" /></a>@endif
                    @if($listing->facebook)<a href="{{ $listing->facebook }}" target="_blank" rel="nofollow noopener noreferrer" class="{{ $ext }}"><x-plat.icon name="link" class="w-4 h-4" /> Facebook page <x-plat.icon name="arrow-up-right" class="w-3.5 h-3.5" /></a>@endif
                </div>
            </div>
        @endif

        <div class="text-[13px] text-slate2">
            <h2 class="text-[15px] font-extrabold text-night">Sources</h2>
            <ul class="mt-2 space-y-1">
                @foreach($listing->sources ?? [] as $src)
                    <li class="break-all"><a href="{{ $src }}" target="_blank" rel="nofollow noopener noreferrer" class="hover:text-leaf hover:underline">{{ $src }}</a></li>
                @endforeach
            </ul>
            @if($listing->source_checked_at)<p class="mt-2">Information checked on {{ $listing->source_checked_at->format('j F Y') }}.</p>@endif
            @if($listing->ownership_note)<p class="mt-1">{{ $listing->ownership_note }}</p>@endif
        </div>
    </div>

    <aside class="h-fit rounded-[20px] border border-hair bg-white p-5">
        <h2 class="font-extrabold">Is this your brand?</h2>
        <p class="mt-1.5 text-[13.5px] text-slate2">Register it to get your own profile. After our team checks your identity and ownership, your profile replaces this listing.</p>
        <a href="{{ route('owner.register') }}" class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-leaf px-4 py-3 text-sm font-bold text-white hover:bg-leafdk">List your brand</a>
        <p class="mt-3 text-[12px] text-slate2">Something wrong here? Contact MetaSoft BD support (details below) and we will correct or remove it.</p>
    </aside>
</section>

@if($related->isNotEmpty())
    <section class="{{ $wrap }} pb-12">
        <h2 class="text-lg font-extrabold">More in {{ $listing->category?->name ?? 'the directory' }}</h2>
        <div class="mt-4 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4">
            @foreach($related as $r)
                <x-plat.listing-card :listing="$r" />
            @endforeach
        </div>
    </section>
@endif
@endsection

@section('footer')
    <x-plat.site-footer />
@endsection
