@extends('layouts.platform')

@section('title', $campaign->title.' — Vote | MetaSoft BD')
@section('meta_description', Str::limit($campaign->description ?: 'Vote for your favourite Bangladeshi brands in '.$campaign->title.'.', 155))
@section('og_title', $campaign->title)
@section('og_description', 'Vote for your favourite Bangladeshi brands on MetaSoft BD.')

@section('page')
@php $phase = $campaign->phase(); @endphp
<section class="bg-[linear-gradient(160deg,#0A1428,#0E2350_55%,#00513C)] py-10 text-white sm:py-14">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        @if($campaign->award)
            <a href="{{ route('awards.show', $campaign->award->slug) }}" class="text-[12px] font-extrabold uppercase tracking-[0.16em] text-gold hover:underline">{{ $campaign->award->title }}</a>
        @endif
        <h1 class="mt-2 text-[28px] font-extrabold leading-tight tracking-tight sm:text-[40px]">{{ $campaign->title }}</h1>
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <span @class(['rounded-full px-3 py-1 font-bold', 'bg-gold text-night' => $phase === 'open', 'bg-white/15' => $phase !== 'open'])>{{ $campaign->phaseLabel() }}</span>
            @if($campaign->starts_at)<span class="text-white/70">From {{ $campaign->starts_at->format('j M Y') }}</span>@endif
            @if($campaign->ends_at)<span class="text-white/70">until {{ $campaign->ends_at->format('j M Y, g:i A') }}</span>@endif
        </div>
        @if($campaign->description)
            <p class="mt-4 max-w-2xl whitespace-pre-line text-[15px] leading-relaxed text-white/75">{{ $campaign->description }}</p>
        @endif
    </div>
</section>

<section class="mx-auto max-w-5xl space-y-8 px-4 py-10 sm:px-6">
    @forelse($campaign->categories as $category)
        <div>
            <h2 class="mb-3 text-xl font-extrabold">{{ $category->name }}</h2>
            @if($category->entries->isEmpty())
                <p class="rounded-2xl border border-dashed border-hair bg-white px-4 py-6 text-center text-sm text-slate2">Nominees will be announced soon.</p>
            @else
                <ol class="divide-y divide-hair overflow-hidden rounded-[20px] border border-hair bg-white">
                    @foreach($category->entries as $i => $entry)
                        @php $b = $entry->brand; [$f, $t] = $b->gradient(); @endphp
                        <li class="flex items-center gap-3 p-3 sm:gap-4 sm:p-4">
                            @if($campaign->show_counts)
                                <span class="w-7 shrink-0 text-center text-sm font-extrabold {{ $i === 0 ? 'text-golddk' : 'text-slate2' }}">#{{ $i + 1 }}</span>
                            @endif
                            <x-plat.logo :initials="$b->initials()" :from="$f" :to="$t" :size="44" :src="$b->logoUrl()" :alt="$b->name.' logo'" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ $b->profileUrl() }}" class="flex items-center gap-1.5 font-bold hover:text-leaf"><span class="truncate">{{ $b->name }}</span>@if($b->is_verified)<x-plat.verified />@endif</a>
                                <p class="truncate text-[13px] text-slate2">{{ $b->category?->name }} · {{ $b->district }}</p>
                            </div>
                            @if($campaign->show_counts)
                                <span class="hidden text-right text-sm sm:block"><b class="text-base">{{ number_format($entry->votes_count) }}</b> <span class="text-slate2">votes</span></span>
                            @endif
                            @if($phase === 'open')
                                <a href="{{ $b->voteUrl() }}" class="shrink-0 rounded-xl bg-flag px-4 py-2.5 text-sm font-bold text-white hover:brightness-95">Vote</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    @empty
        <p class="rounded-2xl border border-dashed border-hair bg-white px-4 py-10 text-center text-slate2">Categories for this campaign will be announced soon.</p>
    @endforelse

    @if(! $campaign->show_counts)
        <p class="text-center text-xs text-slate2">Vote counts are hidden during this campaign and are published with the results.</p>
    @endif
</section>
@endsection
