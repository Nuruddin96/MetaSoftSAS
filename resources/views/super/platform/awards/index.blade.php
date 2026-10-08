@extends('layouts.super')

@section('title', 'Awards — Brand platform')

@section('content')
@include('super.platform._tabs')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-disp text-2xl font-bold">🏆 Awards</h1>
    <a href="{{ route('super.awards.create') }}" class="rounded-lg bg-leaf px-4 py-2 text-sm font-semibold text-white hover:bg-leafdk">+ New award</a>
</div>

@unless($awards->contains('title', config('platform.award_name')))
    <form method="POST" action="{{ route('super.awards.setup-program') }}" class="mb-5 flex flex-col gap-3 rounded-xl border border-gold/40 bg-gold/10 p-4 sm:flex-row sm:items-center sm:justify-between">
        @csrf
        <div>
            <p class="font-semibold">Set up {{ config('platform.award_name') }}</p>
            <p class="text-sm text-mute">Creates the programme as a draft with {{ count(config('platform.award_categories')) }} categories × People’s Choice + Jury Choice ({{ count(config('platform.award_categories')) * 2 }} awards) and a draft voting campaign where one mobile number can vote only once in the whole programme. Category names can be edited afterwards.</p>
        </div>
        <button class="shrink-0 rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white">Set up programme</button>
    </form>
@endunless

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @forelse($awards as $a)
        <a href="{{ route('super.awards.show', $a) }}" class="block rounded-xl border border-ink/5 bg-white p-4 hover:border-leaf">
            <div class="flex items-start justify-between gap-2">
                <p class="font-semibold">{{ $a->title }}</p>
                <span class="shrink-0 rounded bg-ink/5 px-2 py-0.5 text-xs">{{ $a->statusLabel() }}</span>
            </div>
            <p class="text-sm text-mute">{{ $a->year }}@if($a->is_featured) · ⭐ featured on homepage @endif</p>
            <p class="mt-3 text-sm">{{ $a->categories_count }} categories · {{ $a->nominations_count }} nominations · {{ $a->recognitions_count }} results</p>
            @if($a->pending_nominations)<p class="mt-1 text-sm font-semibold text-amber-700">{{ $a->pending_nominations }} new nomination(s) to review</p>@endif
        </a>
    @empty
        <p class="rounded-xl border border-ink/5 bg-white p-6 text-mute md:col-span-2">No awards yet. Create the first season — e.g. “{{ config('platform.award_name') }}”.</p>
    @endforelse
</div>
@endsection
