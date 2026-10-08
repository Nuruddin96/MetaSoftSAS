@extends('layouts.super')

@section('title', 'Voting campaigns — Brand platform')

@section('content')
@include('super.platform._tabs')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-disp text-2xl font-bold">🗳 Voting campaigns</h1>
    <a href="{{ route('super.campaigns.create') }}" class="rounded-lg bg-leaf px-4 py-2 text-sm font-semibold text-white hover:bg-leafdk">+ New campaign</a>
</div>

<div class="overflow-x-auto rounded-xl border border-ink/5 bg-white">
    <table class="w-full text-sm">
        <thead><tr class="border-b border-ink/10 text-left text-mute"><th class="p-3">Campaign</th><th class="p-3">Phase</th><th class="p-3">Period</th><th class="p-3">Nominees</th><th class="p-3">Valid votes</th><th class="p-3">Flagged</th></tr></thead>
        <tbody>
            @forelse($campaigns as $c)
                <tr class="border-b border-ink/5">
                    <td class="p-3"><a href="{{ route('super.campaigns.show', $c) }}" class="font-semibold text-leaf hover:underline">{{ $c->title }}</a>@if($c->award)<p class="text-xs text-mute">{{ $c->award->title }}</p>@endif</td>
                    <td class="p-3"><span @class(['rounded px-2 py-1 text-xs font-semibold', 'bg-leaf/10 text-leafdk' => $c->isOpen(), 'bg-amber/15 text-amber-700' => in_array($c->phase(), ['scheduled', 'paused']), 'bg-ink/5 text-mute' => in_array($c->phase(), ['draft', 'ended'])])>{{ $c->phaseLabel() }}</span></td>
                    <td class="p-3 text-xs text-mute">{{ $c->starts_at?->format('d M Y') ?? '—' }} → {{ $c->ends_at?->format('d M Y H:i') ?? 'manual' }}</td>
                    <td class="p-3">{{ $c->entries_count }}</td>
                    <td class="p-3 font-semibold">{{ number_format($c->valid_votes) }}</td>
                    <td class="p-3">@if($c->flagged_votes)<a href="{{ route('super.campaigns.votes', [$c, 'flagged' => 1, 'status' => 'valid']) }}" class="font-semibold text-amber-700 hover:underline">{{ number_format($c->flagged_votes) }}</a>@else 0 @endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-mute">No campaigns yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
