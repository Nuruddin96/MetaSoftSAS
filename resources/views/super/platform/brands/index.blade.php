@extends('layouts.super')

@section('title', 'Brands — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $statusCls = ['pending' => 'bg-amber/15 text-amber-700', 'approved' => 'bg-leaf/10 text-leafdk', 'rejected' => 'bg-red-50 text-red-600', 'suspended' => 'bg-ink/10 text-ink'];
    $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
@endphp
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-disp text-2xl font-bold">🏅 Brands</h1>
    <a href="{{ route('super.brands.create') }}" class="rounded-lg bg-leaf px-4 py-2 text-sm font-semibold text-white hover:bg-leafdk">+ Add brand manually</a>
</div>

<div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
    @foreach(\App\Models\Brand::STATUSES as $s)
        <a href="{{ route('super.brands.index', ['status' => $s]) }}" class="rounded-xl border bg-white p-3 {{ request('status') === $s ? 'border-leaf' : 'border-ink/5' }}">
            <p class="text-xs capitalize text-mute">{{ $s }}</p><p class="text-xl font-bold">{{ $counts[$s] ?? 0 }}</p>
        </a>
    @endforeach
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-2 rounded-xl border border-ink/5 bg-white p-3">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone, email, founder, slug" class="{{ $field }} min-w-[200px] flex-1">
    <select name="status" class="{{ $field }}"><option value="">All statuses</option>@foreach(\App\Models\Brand::STATUSES as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <select name="category" class="{{ $field }}"><option value="">All categories</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach</select>
    <select name="division" class="{{ $field }}"><option value="">All divisions</option>@foreach($divisions as $d)<option value="{{ $d }}" @selected(request('division') === $d)>{{ $d }}</option>@endforeach</select>
    <select name="flag" class="{{ $field }}">
        <option value="">Any flag</option>
        @foreach(['verified' => 'Verified', 'unverified' => 'Not verified', 'featured' => 'Featured', 'sponsored' => 'Sponsored', 'changes' => 'Has change request'] as $k => $l)
            <option value="{{ $k }}" @selected(request('flag') === $k)>{{ $l }}</option>
        @endforeach
    </select>
    <button class="rounded-lg bg-ink px-4 py-2 text-sm font-semibold text-white">Filter</button>
    @if(request()->query())<a href="{{ route('super.brands.index') }}" class="px-2 py-2 text-sm text-mute hover:text-ink">Reset</a>@endif
</form>

@if($pendingChanges)
    <a href="{{ route('super.brands.changes') }}" class="mb-4 block rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">{{ $pendingChanges }} profile change {{ Str::plural('request', $pendingChanges) }} waiting for review →</a>
@endif

<div class="overflow-x-auto rounded-xl border border-ink/5 bg-white">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-ink/10 text-left text-mute">
                <th class="p-3">Brand</th><th class="p-3">Owner</th><th class="p-3">Category / location</th><th class="p-3">Status</th><th class="p-3">Flags</th><th class="p-3">Submitted</th>
            </tr>
        </thead>
        <tbody>
            @forelse($brands as $b)
                <tr class="border-b border-ink/5 hover:bg-paper/50">
                    <td class="p-3">
                        <a href="{{ route('super.brands.show', $b) }}" class="flex items-center gap-2.5 font-semibold text-leaf hover:underline">
                            @if($b->logoUrl())<img src="{{ $b->logoUrl() }}" alt="" class="h-9 w-9 rounded-lg object-cover">@else<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-ink/5 text-xs font-bold text-ink">{{ $b->initials() }}</span>@endif
                            <span>{{ $b->name }} @if($b->is_verified)<x-plat.verified class="inline -mt-0.5" />@endif</span>
                        </a>
                    </td>
                    <td class="p-3"><p>{{ $b->founder_name }}</p><p class="text-xs text-mute">{{ $b->phone }}</p></td>
                    <td class="p-3"><p>{{ $b->category?->name ?? '—' }}</p><p class="text-xs text-mute">{{ $b->district }}, {{ $b->division }}</p></td>
                    <td class="p-3"><span class="rounded px-2 py-1 text-xs font-semibold capitalize {{ $statusCls[$b->status] ?? '' }}">{{ $b->status }}</span></td>
                    <td class="p-3 text-xs">
                        <div class="flex flex-wrap gap-1">
                            @if($b->is_featured)<span class="rounded bg-ink px-1.5 py-0.5 text-white">Featured</span>@endif
                            @if($b->isSponsoredNow())<span class="rounded border border-dashed border-ink/30 px-1.5 py-0.5 text-mute">Sponsored</span>@endif
                            @if($b->pending_changes)<span class="rounded bg-sky-100 px-1.5 py-0.5 text-sky-700">Change req.</span>@endif
                            @if(! $b->brand_owner_id)<span class="rounded bg-ink/5 px-1.5 py-0.5 text-mute">No owner login</span>@endif
                        </div>
                    </td>
                    <td class="p-3 text-xs text-mute">{{ $b->created_at?->format('d M Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-mute">No brands match.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $brands->links() }}</div>
@endsection
