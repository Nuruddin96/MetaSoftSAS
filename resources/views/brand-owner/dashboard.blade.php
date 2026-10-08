@extends('layouts.brand-owner')

@section('title', 'Dashboard — MetaSoft BD')

@section('page')
@php $card = 'rounded-[20px] border border-hair bg-white p-5 sm:p-6'; @endphp

<div class="flex flex-wrap items-end justify-between gap-3">
    <div>
        <p class="text-sm font-semibold text-slate2">Welcome back, {{ Str::before($owner->name, ' ') ?: $owner->name }}</p>
        <h1 class="mt-0.5 flex items-center gap-2 text-[24px] font-extrabold tracking-tight sm:text-[28px]">
            {{ $brand?->name ?? 'Your brand' }} @if($brand?->is_verified)<x-plat.verified size="w-6 h-6" />@endif
        </h1>
    </div>
    @if($brand?->isPublished())
        <a href="{{ $brand->profileUrl() }}" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-hair bg-white px-4 py-2.5 text-sm font-bold hover:border-leaf"><x-plat.icon name="eye" class="w-4 h-4" /> Public profile</a>
    @endif
</div>

@if(! $brand)
    <div class="{{ $card }} mt-6 text-center">
        <p class="font-bold">No brand is linked to this account.</p>
        <p class="mt-1 text-sm text-slate2">Please contact MetaSoft BD support.</p>
    </div>
@else
    {{-- Status --}}
    @switch($brand->status)
        @case('pending')
            <div class="mt-5 flex items-start gap-3 rounded-[20px] border border-amber-200 bg-amber-50 p-4 sm:p-5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700"><x-plat.icon name="clock" class="w-5 h-5" /></span>
                <div>
                    <p class="font-extrabold text-amber-900">Pending review</p>
                    <p class="mt-0.5 text-sm text-amber-800">Our team is reviewing {{ $brand->name }} — usually within 24 hours. Meanwhile, complete your profile so it looks great when it goes live.</p>
                </div>
            </div>
            @break
        @case('rejected')
            <div class="mt-5 rounded-[20px] border border-rose-200 bg-rose-50 p-4 sm:p-5">
                <p class="font-extrabold text-rose-800">Your registration needs changes</p>
                @if($brand->status_reason)<p class="mt-1 text-sm text-rose-700"><b>Reason:</b> {{ $brand->status_reason }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('owner.brand.edit') }}" class="rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-rose-700 ring-1 ring-rose-200">Fix my details</a>
                    <form method="POST" action="{{ route('owner.brand.resubmit') }}">@csrf
                        <button class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-700">Resubmit for review</button>
                    </form>
                </div>
            </div>
            @break
        @case('suspended')
            <div class="mt-5 rounded-[20px] border border-rose-200 bg-rose-50 p-4 sm:p-5">
                <p class="font-extrabold text-rose-800">Your public profile is suspended</p>
                @if($brand->status_reason)<p class="mt-1 text-sm text-rose-700"><b>Reason:</b> {{ $brand->status_reason }}</p>@endif
                <p class="mt-1 text-sm text-rose-700">Please contact MetaSoft BD support to resolve this.</p>
            </div>
            @break
        @default
            <div class="mt-5 flex flex-col gap-3 rounded-[20px] bg-[linear-gradient(135deg,#00513C,#0A1428)] p-5 text-white sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <p class="text-[11px] font-extrabold uppercase tracking-[0.16em] text-gold">Live on MetaSoft BD</p>
                    <p class="mt-1 truncate font-mono text-sm text-white/85">{{ Str::after($brand->profileUrl(), '://') }}</p>
                </div>
                <button type="button" data-copy="{{ $brand->profileUrl() }}" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-night"><x-plat.icon name="copy" class="w-4 h-4" /> Copy profile link</button>
            </div>
    @endswitch

    @if($brand->pendingChange)
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
            <span><b>Pending review:</b> changes to {{ implode(', ', array_map(fn ($f) => str_replace(['_id', '_path', '_'], ['', '', ' '], $f), array_keys($brand->pendingChange->changes))) }}.</span>
            <a href="{{ route('owner.brand.edit') }}" class="font-bold underline">View</a>
        </div>
    @endif

    {{-- Quick stats --}}
    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['Status', ucfirst($brand->status === 'approved' ? 'Live' : $brand->status), 'shield'],
            ['Verification', $brand->is_verified ? 'Verified' : 'Not yet', 'check'],
            ['Votes (active)', number_format($entries->sum('votes_count')), 'vote'],
            ['Profile views', number_format($brand->views_count), 'eye'],
        ] as [$k, $v, $icon])
            <div class="rounded-2xl border border-hair bg-white p-4">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-slate2"><x-plat.icon :name="$icon" class="w-3.5 h-3.5" /> {{ $k }}</p>
                <p class="mt-1 text-lg font-extrabold">{{ $v }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        {{-- Completion --}}
        <section class="{{ $card }}">
            <div class="flex items-center justify-between">
                <h2 class="font-extrabold">Profile {{ $completion['percent'] }}% complete</h2>
                <a href="{{ route('owner.brand.edit') }}" class="text-sm font-bold text-leaf hover:underline">Edit</a>
            </div>
            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-cloud" role="progressbar" aria-valuenow="{{ $completion['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-leaf" style="width: {{ $completion['percent'] }}%"></div>
            </div>
            @if($completion['missing'])
                <p class="mt-4 text-xs font-semibold text-slate2">Optional — helps customers and the jury know you better:</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach($completion['missing'] as $tip)
                        <li><a href="{{ route('owner.brand.edit') }}" class="flex items-center gap-2 text-sm font-semibold hover:text-leaf"><span class="flex h-5 w-5 items-center justify-center rounded-full border border-hair text-slate2">+</span> {{ $tip }}</a></li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm font-semibold text-leafdk">Your profile is complete. 🎉</p>
            @endif
        </section>

        {{-- Voting --}}
        <section class="{{ $card }}">
            <div class="flex items-center justify-between">
                <h2 class="font-extrabold">Voting</h2>
                <a href="{{ route('owner.voting') }}" class="text-sm font-bold text-leaf hover:underline">Open</a>
            </div>
            @forelse($entries as $e)
                <div class="mt-3 rounded-xl bg-cloud p-3">
                    <p class="text-sm font-bold">{{ $e->campaign->title }}</p>
                    <p class="text-xs text-slate2">{{ $e->category->name }} · {{ $e->campaign->phaseLabel() }}</p>
                    <p class="mt-1.5 text-sm"><b>{{ number_format($e->votes_count) }}</b> votes · rank <b>#{{ $e->rank() }}</b></p>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate2">Your brand isn’t in an active voting campaign yet. When it is, your votes, ranking and share kit appear here.</p>
            @endforelse
            @if($entries->isNotEmpty() && $brand->isPublished())
                <button type="button" data-copy="{{ $brand->voteUrl() }}" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-night py-2.5 text-sm font-bold text-white"><x-plat.icon name="copy" class="w-4 h-4" /> Copy voting link</button>
            @endif
        </section>

        {{-- Awards --}}
        <section class="{{ $card }}">
            <div class="flex items-center justify-between">
                <h2 class="font-extrabold">Awards</h2>
                <a href="{{ route('owner.awards') }}" class="text-sm font-bold text-leaf hover:underline">Open</a>
            </div>
            @if($openAwards && $brand->isPublished())
                <p class="mt-3 rounded-xl bg-gold/20 px-3 py-2.5 text-sm font-semibold text-golddk">{{ $openAwards }} {{ Str::plural('award', $openAwards) }} accepting nominations now.</p>
            @endif
            @foreach($recognitions as $r)
                <p class="mt-3 flex items-center gap-2 text-sm font-bold"><x-plat.icon name="trophy" class="w-4 h-4 text-golddk" /> {{ $r->label() }} @if($r->category)· {{ $r->category->name }}@endif</p>
            @endforeach
            @forelse($nominations as $n)
                <div class="mt-3 flex items-center justify-between gap-2 text-sm">
                    <span class="min-w-0 truncate">{{ $n->category?->name }} <span class="text-slate2">· {{ $n->award?->year }}</span></span>
                    <span class="shrink-0 rounded-full bg-cloud px-2.5 py-0.5 text-xs font-bold">{{ $n->statusLabel() }}</span>
                </div>
            @empty
                @if($recognitions->isEmpty())<p class="mt-3 text-sm text-slate2">No nominations yet.</p>@endif
            @endforelse
        </section>

        {{-- Notifications --}}
        <section class="{{ $card }}">
            <div class="flex items-center justify-between">
                <h2 class="font-extrabold">Latest updates</h2>
                <a href="{{ route('owner.notifications') }}" class="text-sm font-bold text-leaf hover:underline">All</a>
            </div>
            @forelse($notifications as $n)
                <div class="mt-3 flex gap-2.5">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $n->read_at ? 'bg-hair' : 'bg-flag' }}"></span>
                    <div class="min-w-0">
                        <p class="text-sm font-bold">{{ $n->title }}</p>
                        <p class="text-xs text-slate2">{{ $n->created_at->diffForHumans() }}</p>
                    </div>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate2">No updates yet.</p>
            @endforelse
        </section>
    </div>
@endif
@endsection
