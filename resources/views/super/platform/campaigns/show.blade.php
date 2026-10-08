@extends('layouts.super')

@section('title', $campaign->title.' — Voting')

@section('content')
@include('super.platform._tabs')
@php
    $box = 'rounded-xl border border-ink/5 bg-white p-4 sm:p-5';
    $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $status = $campaign->status;
    $actions = array_filter([
        'start' => $status === 'draft' ? ['▶ Start voting', 'bg-leaf text-white'] : null,
        'pause' => $status === 'active' ? ['⏸ Pause', 'border border-ink/10 bg-white'] : null,
        'resume' => $status === 'paused' ? ['▶ Resume', 'bg-leaf text-white'] : null,
        'end' => in_array($status, ['active', 'paused'], true) ? ['⏹ End voting', 'bg-red-600 text-white'] : null,
    ]);
@endphp
<a href="{{ route('super.campaigns.index') }}" class="text-sm text-mute hover:text-ink">← All campaigns</a>
<div class="mb-5 mt-2 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="font-disp text-2xl font-bold">{{ $campaign->title }}</h1>
        <p class="text-sm text-mute">
            <b>{{ $campaign->phaseLabel() }}</b> · {{ $campaign->starts_at?->format('d M Y H:i') ?? 'manual start' }} → {{ $campaign->ends_at?->format('d M Y H:i') ?? 'manual end' }}
            · {{ $campaign->vote_limit === 'daily' ? 'daily vote' : 'one vote' }} · counts {{ $campaign->show_counts ? 'public' : 'hidden' }}
            @if($status !== 'draft') · <a href="{{ route('voting.campaign', $campaign->slug) }}" target="_blank" class="text-leaf hover:underline">public page</a>@endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        @foreach($actions as $action => [$label, $cls])
            <form method="POST" action="{{ route('super.campaigns.status', $campaign) }}" @if($action === 'end') onsubmit="return confirm('End voting now? This cannot be undone.')" @endif>@csrf
                <input type="hidden" name="action" value="{{ $action }}"><button class="rounded-lg px-3 py-2 text-sm font-semibold {{ $cls }}">{{ $label }}</button>
            </form>
        @endforeach
        <a href="{{ route('super.campaigns.edit', $campaign) }}" class="rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm font-semibold">✏️ Edit</a>
    </div>
</div>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    @foreach(['Valid votes' => $stats['valid'], 'Invalidated' => $stats['invalid'], 'Flagged (still counted)' => $stats['flagged'], 'Votes today' => $stats['today']] as $k => $v)
        <div class="rounded-xl border border-ink/5 bg-white p-3"><p class="text-xs text-mute">{{ $k }}</p><p class="text-xl font-bold">{{ number_format($v) }}</p></div>
    @endforeach
</div>
<div class="mb-5 flex flex-wrap gap-2 text-sm">
    <a href="{{ route('super.campaigns.votes', $campaign) }}" class="rounded-lg bg-ink px-3 py-2 font-semibold text-white">🔍 Review votes & suspicious activity</a>
    @if($stats['flagged'])<a href="{{ route('super.campaigns.votes', [$campaign, 'flagged' => 1, 'status' => 'valid']) }}" class="rounded-lg bg-amber/20 px-3 py-2 font-semibold text-amber-800">⚠ {{ $stats['flagged'] }} flagged</a>@endif
    <a href="{{ route('super.campaigns.export', $campaign) }}" class="rounded-lg border border-ink/10 bg-white px-3 py-2 font-semibold">⬇ Export CSV</a>
</div>

<div class="grid gap-5 xl:grid-cols-[1fr_340px]">
    <section class="space-y-5">
        @forelse($campaign->categories as $cat)
            @php $total = $cat->entries->where('is_active', true)->sum('votes_count'); $rank = 0; @endphp
            <div class="{{ $box }}">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h2 class="font-bold">{{ $cat->name }} <span class="text-xs font-normal text-mute">· {{ number_format($total) }} valid votes</span></h2>
                    <form method="POST" action="{{ route('super.campaigns.categories.destroy', $cat) }}" onsubmit="return confirm('Remove this category?')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">Remove</button></form>
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        @forelse($cat->entries as $e)
                            <tr class="border-t border-ink/5 {{ $e->is_active ? '' : 'opacity-50' }}">
                                <td class="w-10 py-2 font-bold text-mute">{{ $e->is_active ? '#'.(++$rank) : '—' }}</td>
                                <td class="py-2"><a href="{{ route('super.brands.show', $e->brand_id) }}" class="font-semibold text-leaf hover:underline">{{ $e->brand?->name }}</a>@unless($e->is_active)<span class="ml-1 text-xs text-red-600">removed</span>@endunless</td>
                                <td class="py-2 text-right font-semibold">{{ number_format($e->votes_count) }}</td>
                                <td class="py-2 pl-2 text-right text-xs text-mute">{{ $total && $e->is_active ? round($e->votes_count / $total * 100) : 0 }}%</td>
                                <td class="py-2 pl-3 text-right">
                                    <a href="{{ route('super.campaigns.votes', [$campaign, 'entry' => $e->id]) }}" class="mr-2 text-xs text-leaf hover:underline">votes</a>
                                    <form method="POST" action="{{ route('super.campaigns.entries.toggle', $e) }}" class="inline">@csrf<button class="text-xs {{ $e->is_active ? 'text-red-600' : 'text-leaf' }} hover:underline">{{ $e->is_active ? 'Remove' : 'Re-add' }}</button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-mute">No nominees yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <p class="{{ $box }} text-mute">No categories yet — add one, or import from the linked award.</p>
        @endforelse
    </section>

    <aside class="space-y-5">
        <section class="{{ $box }}">
            <h2 class="mb-2 font-bold">Add nominee</h2>
            @if($campaign->categories->isNotEmpty())
                <form method="POST" action="{{ route('super.campaigns.entries.store', $campaign) }}" class="space-y-2">@csrf
                    <select name="vote_category_id" required class="{{ $field }} w-full">@foreach($campaign->categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach</select>
                    <input name="brand" required placeholder="Brand ID, slug or exact name" class="{{ $field }} w-full">
                    <button class="w-full rounded-lg bg-leaf px-3 py-2 text-sm font-semibold text-white">+ Add to voting</button>
                </form>
                <p class="mt-2 text-xs text-mute">Only approved brands can be added. Owners are notified (once the campaign is live).</p>
            @else
                <p class="text-sm text-mute">Add a category first.</p>
            @endif
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-2 font-bold">Add category</h2>
            <form method="POST" action="{{ route('super.campaigns.categories.store', $campaign) }}" class="flex gap-2">@csrf
                <input name="name" required maxlength="150" placeholder="e.g. Best Food Brand" class="{{ $field }} min-w-0 flex-1">
                <button class="rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white">Add</button>
            </form>
        </section>

        @if($campaign->award)
            <section class="{{ $box }}">
                <h2 class="mb-1 font-bold">Import from {{ $campaign->award->title }}</h2>
                <p class="mb-2 text-xs text-mute">Creates matching categories and adds nominees with these statuses (existing ones are skipped).</p>
                <form method="POST" action="{{ route('super.campaigns.import', $campaign) }}" class="space-y-2">@csrf
                    @foreach(['accepted' => 'Nominees', 'shortlisted' => 'Shortlisted', 'finalist' => 'Finalists'] as $k => $l)
                        <label class="mr-3 inline-flex items-center gap-1.5 text-sm"><input type="checkbox" name="statuses[]" value="{{ $k }}" @checked($k !== 'accepted')> {{ $l }}</label>
                    @endforeach
                    <button class="w-full rounded-lg border border-ink/10 px-3 py-2 text-sm font-semibold">Import nominees</button>
                </form>
            </section>
        @endif
    </aside>
</div>
@endsection
