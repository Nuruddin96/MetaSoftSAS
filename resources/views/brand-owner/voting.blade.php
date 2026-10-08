@extends('layouts.brand-owner')

@section('title', 'Voting — MetaSoft BD')

@section('page')
@php
    $card = 'rounded-[20px] border border-hair bg-white p-5 sm:p-6';
    $current = $entries->first(fn ($e) => $e->campaign->isOpen()) ?? $entries->first();
    $slugName = Str::slug($brand->name) ?: 'brand';
@endphp
<h1 class="text-[24px] font-extrabold tracking-tight sm:text-[28px]">Voting</h1>
<p class="mt-0.5 text-sm text-slate2">Track your votes and share your voting link. Vote counts are read-only — only verified public votes are counted.</p>

@if(! $voteUrl)
    <div class="{{ $card }} mt-5">
        <p class="font-bold">Your voting link appears once your brand is approved.</p>
        <p class="mt-1 text-sm text-slate2">After approval, your permanent link will be <span class="font-mono">{{ url('/vote') }}/your-brand</span>.</p>
    </div>
@else
    <section class="mt-5 rounded-[20px] bg-[linear-gradient(135deg,#0A1428,#0E2350_60%,#00513C)] p-5 text-white sm:p-6">
        <p class="text-[11px] font-extrabold uppercase tracking-[0.16em] text-gold">Your voting link</p>
        <div class="mt-2 flex items-center gap-2 rounded-xl bg-white/10 px-3.5 py-3">
            <x-plat.icon name="link" class="w-4 h-4 shrink-0 text-white/70" />
            <span class="min-w-0 flex-1 truncate font-mono text-sm sm:text-[15px]" title="{{ $voteUrl }}">{{ Str::after($voteUrl, '://') }}</span>
        </div>
        <p class="mt-2 text-xs text-white/60">This link is permanent and always points to your brand’s voting page.</p>
        <x-plat.share-kit class="mt-4 text-night" on-dark :url="$voteUrl" :title="'Vote for '.$brand->name"
            :text="'Please vote for '.$brand->name.($current ? ' in '.$current->campaign->title : '').' on MetaSoft BD 🙏'" />
    </section>
@endif

@forelse($entries as $e)
    @php
        $c = $e->campaign;
        $total = (int) ($e->category->category_votes ?? 0);
        $share = $total > 0 ? round($e->votes_count / $total * 100) : 0;
    @endphp
    <section class="{{ $card }} mt-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-leaf">{{ $c->title }}</p>
                <h2 class="mt-0.5 text-lg font-extrabold">{{ $e->category->name }}</h2>
            </div>
            <span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-mint text-leafdk' => $c->isOpen(), 'bg-amber-50 text-amber-700' => in_array($c->phase(), ['scheduled', 'paused']), 'bg-cloud text-slate2' => $c->phase() === 'ended'])>{{ $c->phaseLabel() }}</span>
        </div>
        @if(! $e->is_active)
            <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">Your brand is not currently active in this category. Contact support if you think this is a mistake.</p>
        @endif
        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl bg-cloud p-3"><dt class="text-xs font-semibold text-slate2">Your votes</dt><dd class="text-xl font-extrabold">{{ number_format($e->votes_count) }}</dd></div>
            <div class="rounded-xl bg-cloud p-3"><dt class="text-xs font-semibold text-slate2">Position</dt><dd class="text-xl font-extrabold">#{{ $e->rank() }}</dd></div>
            <div class="rounded-xl bg-cloud p-3"><dt class="text-xs font-semibold text-slate2">Share of category</dt><dd class="text-xl font-extrabold">{{ $share }}%</dd></div>
            <div class="rounded-xl bg-cloud p-3"><dt class="text-xs font-semibold text-slate2">{{ $c->phase() === 'scheduled' ? 'Starts' : 'Ends' }}</dt>
                <dd class="text-sm font-bold">{{ ($c->phase() === 'scheduled' ? $c->starts_at : $c->ends_at)?->format('j M, g:i A') ?? '—' }}</dd></div>
        </dl>
        @if($c->starts_at)<p class="mt-2 text-xs text-slate2">Voting period: {{ $c->starts_at->format('j M Y') }} – {{ $c->ends_at?->format('j M Y') ?? 'until closed' }} · {{ $c->vote_limit === 'daily' ? 'one vote per number per day' : 'one vote per number' }}</p>@endif
        @unless($c->show_counts)<p class="mt-2 text-xs text-slate2">Counts are hidden from the public during this campaign; only you can see your own numbers.</p>@endunless
    </section>
@empty
    <div class="{{ $card }} mt-5 text-center">
        <x-plat.icon name="vote" class="mx-auto w-8 h-8 text-slate2" />
        <p class="mt-2 font-bold">Your brand isn’t in a voting campaign yet</p>
        <p class="mt-1 text-sm text-slate2">Nominate your brand under <a href="{{ route('owner.awards') }}" class="font-semibold text-leaf underline">Awards</a>. Shortlisted brands are added to public voting by the MetaSoft BD team.</p>
    </div>
@endforelse

@if($voteUrl)
    <div class="mt-5 grid gap-5 lg:grid-cols-[1fr_280px]">
        <section class="{{ $card }}">
            <h2 class="font-extrabold">Ready-made share card</h2>
            <p class="mt-0.5 text-sm text-slate2">Post it on Facebook, WhatsApp status or Instagram — no design work needed.</p>
            <canvas id="shareCard" data-share-card width="1080" height="1080"
                    data-name="{{ $brand->name }}" data-initials="{{ $brand->initials() }}" data-logo="{{ $brand->logoUrl() }}"
                    data-category="{{ $current?->category->name }}" data-campaign="{{ $current?->campaign->title ?? 'MetaSoft BD Awards '.now()->year }}"
                    data-url="{{ $voteUrl }}" data-cta="Vote Now"
                    class="mt-4 aspect-square w-full max-w-[420px] rounded-2xl border border-hair bg-night" aria-label="Share card preview"></canvas>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" data-share-card-download="shareCard" data-filename="{{ $slugName }}-vote-card.png" class="rounded-xl bg-night px-4 py-2.5 text-sm font-bold text-white hover:bg-navy">Download card</button>
                <button type="button" data-share-card-share="shareCard" class="rounded-xl border border-hair px-4 py-2.5 text-sm font-bold hover:border-leaf">Share card</button>
            </div>
        </section>
        <section class="{{ $card }}">
            <h2 class="font-extrabold">QR code</h2>
            <p class="mt-0.5 text-sm text-slate2">Print it on packaging, flyers or your shop counter.</p>
            <div data-qr="{{ $voteUrl }}" class="mx-auto mt-4 aspect-square w-full max-w-[220px] rounded-xl border border-hair bg-white p-2"></div>
            <button type="button" data-qr-download="{{ $voteUrl }}" data-filename="{{ $slugName }}-vote-qr.png" class="mt-3 w-full rounded-xl border border-hair px-4 py-2.5 text-sm font-bold hover:border-leaf">Download QR</button>
        </section>
    </div>
@endif

<p class="mt-5 flex items-start gap-2 text-xs leading-relaxed text-slate2">
    <x-plat.icon name="shield" class="mt-0.5 w-4 h-4 shrink-0" />
    Please don’t buy votes or use fake numbers. MetaSoft BD checks every vote for duplicate numbers, devices and unusual patterns, and removes invalid votes from the totals.
</p>
@endsection
