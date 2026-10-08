@extends('layouts.platform')

@php $awardName = $entries->first()?->campaign->award?->title ?? $entries->first()?->campaign->title ?? config('platform.award_name'); @endphp
@section('title', 'Vote for '.$brand->name.' — '.$awardName.' | MetaSoft BD')
@section('meta_description', 'Support '.$brand->name.' in '.$awardName.' — cast your vote on MetaSoft BD.')
{{-- Shared links (Facebook, WhatsApp, Messenger) unfurl into a clickable preview card built from these tags. --}}
@section('og_title', 'Vote for '.$brand->name.' — '.$awardName)
@section('og_description', ($brand->category?->name ? $brand->category->name.' · ' : '').$brand->district.' — tap to vote on MetaSoft BD.')
@if($brand->logoUrl())
    @section('og_image', $brand->logoUrl())
@endif

@push('head')
    @if($turnstileKey)<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>@endif
@endpush

@section('page')
@php [$from, $to] = $brand->gradient(); @endphp
<section class="bg-[linear-gradient(160deg,#0A1428,#0E2350_55%,#00513C)] pb-24 pt-10 text-white sm:pt-14">
    <div class="mx-auto max-w-2xl px-4 text-center sm:px-6">
        <x-plat.logo :initials="$brand->initials()" :from="$from" :to="$to" :size="88" ring :src="$brand->logoUrl()" :alt="$brand->name.' logo'" class="mx-auto shadow-xl" />
        <h1 class="mt-4 flex items-center justify-center gap-2 text-[28px] font-extrabold leading-tight tracking-tight sm:text-[36px]">
            {{ $brand->name }} @if($brand->is_verified)<x-plat.verified size="w-6 h-6" />@endif
        </h1>
        <p class="mt-1 text-sm text-white/70">{{ $brand->category?->name }} · {{ $brand->district }}</p>
        <a href="{{ $brand->profileUrl() }}" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-gold hover:underline">View brand profile <x-plat.icon name="arrow-right" class="w-3.5 h-3.5" /></a>
    </div>
</section>

<section class="mx-auto -mt-16 max-w-2xl space-y-4 px-4 pb-14 sm:px-6">
    @if(session('voted'))
        <x-plat.flash />
    @endif

    @forelse($entries as $entry)
        @php $c = $entry->campaign; $open = $c->isOpen(); @endphp
        <article class="rounded-[24px] border border-hair bg-white p-5 shadow-[0_16px_40px_-24px_rgba(10,20,40,0.35)] sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-extrabold uppercase tracking-[0.16em] text-leaf">{{ $c->title }}</p>
                    <h2 class="mt-1 text-xl font-extrabold">{{ $entry->category->name }}</h2>
                </div>
                <span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-mint text-leafdk' => $open, 'bg-amber-50 text-amber-700' => in_array($c->phase(), ['scheduled', 'paused']), 'bg-cloud text-slate2' => $c->phase() === 'ended'])>{{ $c->phaseLabel() }}</span>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                @if($c->show_counts)
                    <div class="rounded-xl bg-cloud px-3 py-2.5"><dt class="text-xs font-semibold text-slate2">Votes</dt><dd class="text-lg font-extrabold" data-votes-for="{{ $entry->id }}">{{ number_format($entry->votes_count) }}</dd></div>
                    <div class="rounded-xl bg-cloud px-3 py-2.5"><dt class="text-xs font-semibold text-slate2">Position</dt><dd class="text-lg font-extrabold" data-rank-for="{{ $entry->id }}">#{{ $entry->rank() }}</dd></div>
                @endif
                <div class="col-span-2 rounded-xl bg-cloud px-3 py-2.5 sm:col-span-1">
                    <dt class="text-xs font-semibold text-slate2">{{ $c->phase() === 'scheduled' ? 'Starts' : ($c->phase() === 'ended' ? 'Ended' : 'Ends') }}</dt>
                    <dd class="font-bold">{{ ($c->phase() === 'scheduled' ? $c->starts_at : $c->ends_at)?->format('j M Y, g:i A') ?? 'When results are announced' }}</dd>
                </div>
            </dl>

            @if($open)
                <form method="POST" action="{{ route('vote.cast', $brand->slug) }}" data-vote-form data-entry="{{ $entry->id }}" class="mt-5" novalidate>
                    @csrf
                    <input type="hidden" name="entry_id" value="{{ $entry->id }}">
                    <input type="hidden" name="form_token" value="{{ $formToken }}">
                    <div class="absolute -left-[9999px]" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <div data-vote-fields>
                        <label for="phone-{{ $entry->id }}" class="text-sm font-bold">Your mobile number</label>
                        <div class="mt-1.5 flex flex-col gap-2 sm:flex-row">
                            <input id="phone-{{ $entry->id }}" type="tel" name="phone" inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" required
                                   value="{{ old('entry_id') == $entry->id ? old('phone') : '' }}"
                                   class="w-full rounded-xl border border-hair px-4 py-3.5 text-[17px] font-semibold tracking-wide focus:border-leaf focus:outline-none focus:ring-2 focus:ring-leaf/20">
                            <button type="submit" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-flag px-6 py-3.5 text-[15px] font-bold text-white hover:brightness-95 disabled:opacity-60">
                                <x-plat.icon name="vote" class="w-4 h-4" /> Vote
                            </button>
                        </div>
                        @if($turnstileKey)<div class="cf-turnstile mt-3" data-sitekey="{{ $turnstileKey }}"></div>@endif
                        <p class="mt-2 text-xs text-slate2">{{ $c->voteRuleText() }}. Your number is never shown publicly.</p>
                    </div>
                    <div data-vote-msg aria-live="polite">
                        @if($errors->has('vote') && old('entry_id') == $entry->id)
                            <p class="mt-3 rounded-xl bg-rose-50 px-3.5 py-3 text-sm font-semibold text-rose-700">{{ $errors->first('vote') }}</p>
                        @endif
                    </div>
                </form>
                <div data-after-vote="{{ $entry->id }}" class="mt-4 hidden rounded-2xl border border-hair p-4">
                    <p class="text-sm font-bold">Help {{ $brand->name }} win — share this page:</p>
                    <x-plat.share-kit class="mt-3" compact :url="$brand->voteUrl()" :title="'Vote for '.$brand->name" :text="'I just voted for '.$brand->name.' in '.$c->title.'. Vote here:'" />
                </div>
            @elseif($c->phase() === 'scheduled')
                <p class="mt-5 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Voting hasn’t started yet. Save this link and come back {{ $c->starts_at ? 'on '.$c->starts_at->format('j M') : 'soon' }}.</p>
            @elseif($c->phase() === 'paused')
                <p class="mt-5 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">Voting is paused for a moment. Please try again later.</p>
            @else
                <p class="mt-5 rounded-xl bg-cloud px-4 py-3 text-sm font-semibold text-slate2">Voting has closed. Results are announced after review.</p>
            @endif

            <a href="{{ route('voting.campaign', $c->slug) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-leaf hover:underline">See all nominees in {{ $c->title }} <x-plat.icon name="chevron-right" class="w-3.5 h-3.5" /></a>
        </article>
    @empty
        <article class="rounded-[24px] border border-hair bg-white p-7 text-center shadow-[0_16px_40px_-24px_rgba(10,20,40,0.35)]">
            <x-plat.icon name="vote" class="mx-auto w-8 h-8 text-slate2" />
            <h2 class="mt-3 text-lg font-extrabold">No voting is open for {{ $brand->name }} right now</h2>
            <p class="mt-1 text-sm text-slate2">When {{ $brand->name }} is a nominee in a MetaSoft BD voting campaign, you can vote for it here.</p>
            <a href="{{ $brand->profileUrl() }}" class="mt-5 inline-flex rounded-xl bg-night px-5 py-3 text-sm font-bold text-white hover:bg-navy">View brand profile</a>
        </article>
    @endforelse

    <p class="flex items-start gap-2 px-1 text-xs leading-relaxed text-slate2">
        <x-plat.icon name="shield" class="mt-0.5 w-4 h-4 shrink-0" />
        Votes are checked for duplicate numbers, devices and unusual patterns. Suspicious votes may be removed by the MetaSoft BD team, and every change is logged.
    </p>
</section>
@endsection
