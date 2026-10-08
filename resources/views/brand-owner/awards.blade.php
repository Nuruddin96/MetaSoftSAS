@extends('layouts.brand-owner')

@section('title', 'Awards — MetaSoft BD')

@section('page')
@php
    $card = 'rounded-[20px] border border-hair bg-white p-5 sm:p-6';
    $nominated = $nominations->whereNotIn('status', ['withdrawn'])->pluck('award_category_id')->all();
    $statusCls = [
        'submitted' => 'bg-cloud text-slate2', 'accepted' => 'bg-sky-50 text-sky-700', 'shortlisted' => 'bg-indigo-50 text-indigo-700',
        'finalist' => 'bg-mint text-leafdk', 'rejected' => 'bg-rose-50 text-rose-700', 'withdrawn' => 'bg-cloud text-slate2',
    ];
@endphp
<h1 class="text-[24px] font-extrabold tracking-tight sm:text-[28px]">Awards</h1>
<p class="mt-0.5 text-sm text-slate2">Nominate your brand while nominations are open, then follow its progress. Shortlists, finalists and winners are decided by MetaSoft BD and the jury.</p>

@if($recognitions->isNotEmpty())
    <section class="mt-5 rounded-[20px] bg-[linear-gradient(135deg,#9A6B0A,#0A1428)] p-5 text-white sm:p-6">
        <h2 class="font-extrabold">Your recognition</h2>
        <ul class="mt-3 space-y-2">
            @foreach($recognitions as $r)
                <li class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gold text-night"><x-plat.icon name="trophy" class="w-5 h-5" /></span>
                    <span><b>{{ $r->label() }}</b>@if($r->category) · {{ $r->category->name }}@endif<span class="block text-sm text-white/70">{{ $r->award?->title }}</span></span></li>
            @endforeach
        </ul>
    </section>
@endif

<section class="mt-5">
    <h2 class="mb-3 text-lg font-extrabold">Your nominations</h2>
    @forelse($nominations as $n)
        <div class="mb-2 flex flex-col gap-2 rounded-2xl border border-hair bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="font-bold">{{ $n->category?->name }}</p>
                <p class="text-sm text-slate2">{{ $n->award?->title }} · {{ $n->source === 'admin' ? 'Nominated by MetaSoft BD' : 'Submitted '.$n->created_at->format('j M Y') }}</p>
                @if($n->admin_note && in_array($n->status, ['rejected', 'accepted', 'shortlisted', 'finalist'], true))<p class="mt-1 text-sm text-slate2">Note: {{ $n->admin_note }}</p>@endif
            </div>
            <div class="flex shrink-0 items-center gap-3">
                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusCls[$n->status] ?? 'bg-cloud' }}">{{ $n->statusLabel() }}</span>
                @if(in_array($n->status, ['submitted', 'accepted'], true))
                    <form method="POST" action="{{ route('owner.awards.withdraw', $n->id) }}" onsubmit="return confirm('Withdraw this nomination?')">@csrf
                        <button class="text-xs font-bold text-slate2 hover:text-rose-600">Withdraw</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <p class="rounded-2xl border border-dashed border-hair bg-white px-4 py-6 text-center text-sm text-slate2">No nominations yet.</p>
    @endforelse
</section>

<section class="mt-6">
    <h2 class="mb-3 text-lg font-extrabold">Awards</h2>
    @forelse($awards as $award)
        @php $eligible = $award->categories->filter(fn ($c) => $c->accepts($brand) && ! in_array($c->id, $nominated, true)); @endphp
        <article class="{{ $card }} mb-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="text-lg font-extrabold">{{ $award->title }}</h3>
                    <p class="text-sm text-slate2">{{ $award->year }}@if($award->nomination_ends_at) · nominations close {{ $award->nomination_ends_at->format('j M Y') }}@endif</p>
                </div>
                <span @class(['rounded-full px-3 py-1 text-xs font-bold', 'bg-gold/25 text-golddk' => $award->acceptsNominations(), 'bg-cloud text-slate2' => ! $award->acceptsNominations()])>{{ $award->statusLabel() }}</span>
            </div>
            @if($award->description)<p class="mt-2 line-clamp-3 text-sm text-slate2">{{ $award->description }}</p>@endif

            @if($award->categories->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach($award->categories as $cat)
                        <span @class(['rounded-full border px-2.5 py-1 text-xs font-semibold', 'border-leaf/40 text-leafdk' => $cat->accepts($brand), 'border-hair text-slate2 line-through decoration-slate-300' => ! $cat->accepts($brand)])
                              title="{{ $cat->accepts($brand) ? 'Your brand is eligible' : 'Only for '.$cat->brandCategory?->name.' brands' }}">{{ $cat->name }}</span>
                    @endforeach
                </div>
            @endif

            @if($award->acceptsNominations())
                @if(! $brand->isPublished())
                    <p class="mt-4 rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-semibold text-amber-800">Your brand can be nominated once it is approved.</p>
                @elseif($eligible->isEmpty())
                    <p class="mt-4 rounded-xl bg-cloud px-3 py-2.5 text-sm font-semibold text-slate2">You’ve nominated your brand in every category it is eligible for.</p>
                @else
                    <form method="POST" action="{{ route('owner.awards.nominate') }}" class="mt-4 space-y-3 rounded-2xl bg-cloud p-4">
                        @csrf
                        <label class="block">
                            <span class="text-sm font-bold">Category</span>
                            <select name="award_category_id" required class="mt-1.5 w-full rounded-xl border border-hair bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none">
                                @foreach($eligible as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold">Why should your brand win? <span class="font-normal text-slate2">(optional)</span></span>
                            <textarea name="statement" rows="3" maxlength="1500" class="mt-1.5 w-full rounded-xl border border-hair bg-white px-3.5 py-3 text-[15px] focus:border-leaf focus:outline-none" placeholder="Achievements, impact, customers served…"></textarea>
                        </label>
                        <button class="w-full rounded-xl bg-leaf py-3 text-sm font-bold text-white hover:bg-leafdk sm:w-auto sm:px-6">Submit nomination</button>
                    </form>
                @endif
            @endif
            <a href="{{ route('awards.show', $award->slug) }}" target="_blank" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-leaf hover:underline">Award page & rules <x-plat.icon name="arrow-up-right" class="w-3.5 h-3.5" /></a>
        </article>
    @empty
        <p class="rounded-2xl border border-dashed border-hair bg-white px-4 py-8 text-center text-sm text-slate2">No awards have been announced yet. You’ll get a notification when nominations open.</p>
    @endforelse
</section>
@endsection
