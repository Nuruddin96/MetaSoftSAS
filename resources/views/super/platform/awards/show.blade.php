@extends('layouts.super')

@section('title', $award->title.' — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $box = 'rounded-xl border border-ink/5 bg-white p-4 sm:p-5';
    $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $statusCls = ['submitted' => 'bg-amber/15 text-amber-700', 'accepted' => 'bg-sky-50 text-sky-700', 'shortlisted' => 'bg-indigo-50 text-indigo-700', 'finalist' => 'bg-leaf/10 text-leafdk', 'rejected' => 'bg-red-50 text-red-600', 'withdrawn' => 'bg-ink/5 text-mute'];
@endphp
<a href="{{ route('super.awards.index') }}" class="text-sm text-mute hover:text-ink">← All awards</a>
<div class="mb-5 mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="font-disp text-2xl font-bold">{{ $award->title }}</h1>
        <p class="text-sm text-mute">{{ $award->year }} · {{ $award->statusLabel() }}@if($award->status !== 'draft') · <a href="{{ route('awards.show', $award->slug) }}" target="_blank" class="text-leaf hover:underline">public page</a>@endif</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('super.awards.edit', $award) }}" class="rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm font-semibold">✏️ Edit / status</a>
        <a href="{{ route('super.campaigns.create', ['award' => $award->id]) }}" class="rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white">🗳 New voting campaign</a>
    </div>
</div>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">
    <div class="space-y-5">
        <section class="{{ $box }}">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-bold">Nominations</h2>
                <form method="GET" class="flex gap-2">
                    <select name="status" onchange="this.form.submit()" class="{{ $field }}"><option value="">All statuses</option>@foreach(\App\Models\AwardNomination::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
                </form>
            </div>
            <p class="mb-3 text-xs text-mute">Submitted → Nominee → Shortlisted → Finalist. Finalist is separate from Winner / People’s Choice / Jury Choice (recorded under Results).</p>
            @forelse($award->categories as $cat)
                <h3 class="mt-4 border-b border-ink/10 pb-1 text-sm font-bold">{{ $cat->name }}</h3>
                @forelse($nominations->get($cat->id, collect()) as $n)
                    <div class="flex flex-col gap-2 border-b border-ink/5 py-2 text-sm lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <a href="{{ route('super.brands.show', $n->brand_id) }}" class="font-semibold text-leaf hover:underline">{{ $n->brand?->name }}</a>
                            <span class="text-xs text-mute">· {{ $n->source === 'admin' ? 'by admin' : 'by owner' }} · {{ $n->created_at->format('d M') }}</span>
                            @if($n->statement)<p class="mt-0.5 line-clamp-2 text-xs text-mute" title="{{ $n->statement }}">“{{ $n->statement }}”</p>@endif
                        </div>
                        <form method="POST" action="{{ route('super.awards.nominations.update', $n) }}" class="flex shrink-0 flex-wrap items-center gap-1.5">
                            @csrf @method('PUT')
                            <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $statusCls[$n->status] ?? '' }}">{{ $n->statusLabel() }}</span>
                            <select name="status" class="{{ $field }} py-1.5">@foreach(\App\Models\AwardNomination::STATUSES as $k => $l)<option value="{{ $k }}" @selected($n->status === $k)>{{ $l }}</option>@endforeach</select>
                            <input name="admin_note" value="{{ $n->admin_note }}" placeholder="Note (optional)" maxlength="500" class="{{ $field }} w-36 py-1.5">
                            <button class="rounded-lg bg-ink px-2.5 py-1.5 text-xs font-semibold text-white">Save</button>
                        </form>
                    </div>
                @empty
                    <p class="py-2 text-sm text-mute">No nominations{{ request('status') ? ' with this status' : '' }}.</p>
                @endforelse
            @empty
                <p class="text-sm text-mute">Add categories first (right).</p>
            @endforelse

            @if($award->categories->isNotEmpty())
                <form method="POST" action="{{ route('super.awards.nominations.store', $award) }}" class="mt-4 flex flex-wrap gap-2 rounded-lg bg-paper p-3">
                    @csrf
                    <select name="award_category_id" required class="{{ $field }}">@foreach($award->categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach</select>
                    <input name="brand" required placeholder="Brand ID, slug or exact name" class="{{ $field }} min-w-[200px] flex-1">
                    <button class="rounded-lg bg-leaf px-3 py-2 text-sm font-semibold text-white">+ Nominate brand</button>
                </form>
            @endif
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-1 font-bold">Results</h2>
            <p class="mb-3 text-xs text-mute">Winner, People’s Choice and Jury Choice are separate recognitions. Sponsorship never qualifies a brand for any of them.</p>
            @forelse($award->recognitions as $r)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink/5 py-2 text-sm">
                    <span>🏆 <b>{{ \App\Models\AwardRecognition::TYPES[$r->type] }}</b>@if($r->category) · {{ $r->category->name }}@endif — <a href="{{ route('super.brands.show', $r->brand_id) }}" class="text-leaf hover:underline">{{ $r->brand?->name }}</a>@if($r->title) <span class="text-xs text-mute">(“{{ $r->title }}”)</span>@endif</span>
                    <form method="POST" action="{{ route('super.awards.recognitions.destroy', $r) }}" class="flex gap-1">@csrf @method('DELETE')
                        <input name="reason" required placeholder="Reason" class="{{ $field }} w-32 py-1">
                        <button class="text-xs text-red-600 hover:underline">Remove</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-mute">No results recorded yet.</p>
            @endforelse
            <form method="POST" action="{{ route('super.awards.recognitions.store', $award) }}" class="mt-4 grid gap-2 rounded-lg bg-paper p-3 sm:grid-cols-2">
                @csrf
                <select name="type" required class="{{ $field }}">@foreach(\App\Models\AwardRecognition::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                <select name="award_category_id" class="{{ $field }}"><option value="">Overall (no category)</option>@foreach($award->categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach</select>
                <input name="brand" required placeholder="Brand ID, slug or exact name" class="{{ $field }}">
                <input name="title" maxlength="200" placeholder="Custom label (optional)" class="{{ $field }}">
                <button class="rounded-lg bg-amber px-3 py-2 text-sm font-semibold text-ink sm:col-span-2">Record result & notify owner</button>
            </form>
        </section>
    </div>

    <aside class="space-y-5">
        <section class="{{ $box }}">
            <h2 class="mb-3 font-bold">Categories</h2>
            @foreach($award->categories as $cat)
                <div class="flex items-start justify-between gap-2 border-b border-ink/5 py-2 text-sm">
                    <div><p class="font-semibold">{{ $cat->name }}</p><p class="text-xs text-mute">{{ $cat->brandCategory ? 'Only '.$cat->brandCategory->name.' brands' : 'Open to all brands' }}</p></div>
                    <form method="POST" action="{{ route('super.awards.categories.destroy', $cat) }}">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">Remove</button></form>
                </div>
            @endforeach
            <form method="POST" action="{{ route('super.awards.categories.store', $award) }}" class="mt-3 space-y-2">
                @csrf
                <input name="name" required maxlength="150" placeholder="e.g. Best Fashion Brand" class="{{ $field }} w-full">
                <input name="description" maxlength="500" placeholder="Short description (optional)" class="{{ $field }} w-full">
                <select name="brand_category_id" class="{{ $field }} w-full"><option value="">Eligible: all brands</option>@foreach($brandCategories as $bc)<option value="{{ $bc->id }}">Eligible: {{ $bc->name }} only</option>@endforeach</select>
                <button class="w-full rounded-lg bg-leaf px-3 py-2 text-sm font-semibold text-white">+ Add category</button>
            </form>
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-2 font-bold">Voting campaigns</h2>
            @forelse($award->campaigns as $c)
                <a href="{{ route('super.campaigns.show', $c) }}" class="block py-1 text-sm text-leaf hover:underline">{{ $c->title }} <span class="text-xs text-mute">({{ $c->phaseLabel() }})</span></a>
            @empty
                <p class="text-sm text-mute">None yet.</p>
            @endforelse
        </section>

        <section class="{{ $box }} text-sm">
            <h2 class="mb-2 font-bold">Dates</h2>
            <p>Nominations: {{ $award->nomination_starts_at?->format('d M Y') ?? '—' }} → {{ $award->nomination_ends_at?->format('d M Y') ?? '—' }}</p>
            <p>Voting: {{ $award->voting_starts_at?->format('d M Y') ?? '—' }} → {{ $award->voting_ends_at?->format('d M Y') ?? '—' }}</p>
            @if($award->jury_info)<h3 class="mt-3 font-semibold">Jury</h3><p class="whitespace-pre-line text-mute">{{ $award->jury_info }}</p>@endif
        </section>
    </aside>
</div>
@endsection
