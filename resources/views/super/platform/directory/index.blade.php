@extends('layouts.super')

@section('title', 'Directory — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $btn = 'rounded-lg px-3 py-1.5 text-xs font-semibold';
    $labels = ['active' => 'Active', 'claim_pending' => 'Claim pending', 'needs_review' => 'Needs review', 'archived' => 'Archived', 'expired' => 'Past re-check date'];
@endphp
<div class="mb-2 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-disp text-2xl font-bold">📒 Brand directory</h1>
    <a href="{{ route('brands.index') }}#listings" target="_blank" class="text-sm font-semibold text-leaf hover:underline">View public directory ↗</a>
</div>
<p class="mb-4 max-w-3xl text-sm text-mute">Well-known brands listed by the team from their official websites — <b>not</b> registered brands. They never get the Verified badge, votes or awards. Only rows marked <i>verified</i> (facts checked at the official source) and within their re-check date are public. Nothing here is ever deleted: archive and restore instead. Data file: <code>database/data/brand-directory.json</code> (<code>php artisan platform:directory-import</code>).</p>

@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
@endif

<div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
    @foreach($labels as $k => $l)
        <a href="{{ route('super.directory.index', ['filter' => $k]) }}" class="rounded-xl border bg-white p-3 {{ $filter === $k ? 'border-leaf' : 'border-ink/5' }}">
            <p class="text-xs text-mute">{{ $l }}</p><p class="text-xl font-bold">{{ $counts[$k] }}</p>
        </a>
    @endforeach
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2 rounded-xl border border-ink/5 bg-white p-3">
    <input type="search" name="q" value="{{ $q }}" placeholder="Name or domain" class="{{ $field }} min-w-[200px] flex-1">
    @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
    <button class="rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white">Search</button>
    @if($q || $filter)<a href="{{ route('super.directory.index') }}" class="px-2 py-2 text-sm text-mute hover:text-ink">Reset</a>@endif
</form>

<div class="overflow-x-auto rounded-xl border border-ink/5 bg-white">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-ink/10 text-left text-mute">
                <th class="p-3">Listing</th><th class="p-3">Source check</th><th class="p-3">Membership</th><th class="p-3">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($listings as $l)
                <tr class="border-b border-ink/5 align-top">
                    <td class="p-3">
                        <p class="font-semibold">
                            @if($l->isListed())<a href="{{ $l->url() }}" target="_blank" class="text-leaf hover:underline">{{ $l->name }}</a>@else{{ $l->name }}@endif
                            <span class="ml-1 rounded px-1.5 py-0.5 text-[11px] {{ $l->status === 'active' ? 'bg-leaf/10 text-leafdk' : 'bg-ink/10 text-ink' }}">{{ $l->status }}@if($l->archive_reason) · {{ $l->archive_reason }}@endif</span>
                            {{-- Active but not on public pages: needs_review, past its re-check date or replaced. --}}
                            @if($l->status === 'active' && ! $l->isListed())<span class="ml-1 rounded bg-amber/15 px-1.5 py-0.5 text-[11px] text-amber-700" title="Not shown on public pages">not public</span>@endif
                        </p>
                        <p class="text-xs text-mute">{{ $l->category?->name ?? 'No category' }} · {{ $l->official_domain ?? 'no website' }} · priority {{ $l->replacement_priority }}@if($l->homepage_order) · homepage #{{ $l->homepage_order }}@endif</p>
                        @if($l->review_note)<p class="mt-1 max-w-md text-xs text-amber-800">{{ $l->review_note }}</p>@endif
                    </td>
                    <td class="p-3 text-xs">
                        <span class="rounded px-1.5 py-0.5 font-semibold {{ $l->verification_status === 'verified' ? 'bg-sky-50 text-sky-700' : 'bg-amber/15 text-amber-700' }}">{{ $l->verification_status }}</span>
                        <p class="mt-1 text-mute">Checked {{ $l->source_checked_at?->format('d M Y') ?? '—' }}</p>
                        <p class="{{ $l->expires_at?->endOfDay()->isPast() ? 'font-semibold text-red-600' : 'text-mute' }}">Re-check by {{ $l->expires_at?->format('d M Y') ?? '—' }}</p>
                        @if($l->source_url)<a href="{{ $l->source_url }}" target="_blank" rel="noopener" class="text-leaf hover:underline">source ↗</a>@endif
                    </td>
                    <td class="p-3 text-xs">
                        <p class="font-semibold">{{ str_replace('_', ' ', $l->membership_status) }}</p>
                        @if($l->claimedBy)<p class="text-mute">Claim: <a href="{{ route('super.brands.show', $l->claimedBy) }}" class="text-leaf hover:underline">#{{ $l->claimedBy->id }} {{ $l->claimedBy->name }}</a> ({{ $l->claimedBy->status }}{{ $l->claimedBy->is_verified ? ', verified' : '' }})</p>@endif
                        @if($l->replacedBy)<p class="text-mute">Replaced by <a href="{{ route('super.brands.show', $l->replacedBy) }}" class="text-leaf hover:underline">#{{ $l->replacedBy->id }} {{ $l->replacedBy->name }}</a></p>@endif
                    </td>
                    <td class="p-3">
                        @if($l->status === 'active')
                            <form method="POST" action="{{ route('super.directory.replace', $l) }}" class="mb-2 flex flex-wrap gap-1">
                                @csrf
                                <input type="number" name="brand_id" value="{{ $l->claimed_by_brand_id }}" placeholder="Brand #" required class="{{ $field }} w-24 py-1">
                                <button class="{{ $btn }} bg-leaf text-white hover:bg-leafdk" title="Only an approved brand with the Verified badge and identity evidence">Replace</button>
                            </form>
                            <form method="POST" action="{{ route('super.directory.archive', $l) }}" class="flex flex-wrap gap-1">
                                @csrf
                                <input type="text" name="reason" placeholder="Reason" required maxlength="500" class="{{ $field }} w-32 py-1">
                                <button class="{{ $btn }} border border-ink/10 hover:border-red-300 hover:text-red-600">Archive</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('super.directory.restore', $l) }}">
                                @csrf
                                <button class="{{ $btn }} border border-ink/10 hover:border-leaf">Restore</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-6 text-center text-mute">No listings match.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $listings->links() }}</div>

@if($audit->isNotEmpty())
    <section class="mt-6 rounded-xl border border-ink/5 bg-white p-4">
        <h2 class="mb-2 font-bold">Recent directory changes</h2>
        <ul class="space-y-1 text-xs text-mute">
            @foreach($audit as $a)
                <li>{{ $a->created_at?->format('d M Y H:i') }} · {{ $a->actor_name }} · <b class="text-ink">{{ $a->action }}</b> #{{ $a->subject_id }}@if($a->reason) — {{ $a->reason }}@endif</li>
            @endforeach
        </ul>
    </section>
@endif
@endsection
