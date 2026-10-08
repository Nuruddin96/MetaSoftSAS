@extends('layouts.platform')

@section('title', ($category ? $category->name.' brands' : 'Brand directory').' — MetaSoft BD')
@section('meta_description', 'Discover approved Bangladeshi brands on MetaSoft BD — search by name, category and division.')
@section('og_title', 'Bangladeshi brand directory — MetaSoft BD')

@section('page')
@php $wrap = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8'; @endphp
<section class="border-b border-hair bg-white">
    <div class="{{ $wrap }} py-8 sm:py-10">
        <p class="text-[12px] font-extrabold uppercase tracking-[0.16em] text-leaf">Brand directory</p>
        <h1 class="mt-2 text-[28px] font-extrabold leading-tight tracking-tight sm:text-[36px]">
            {{ $category ? $category->name : 'Discover Bangladeshi brands' }}
        </h1>
        <p class="mt-2 max-w-2xl text-[15px] text-slate2">Every brand here is reviewed by the MetaSoft BD team before it is listed. The <x-plat.verified class="inline -mt-0.5" /> badge marks brands whose details we have verified.</p>

        <form method="GET" action="{{ route('brands.index') }}" class="mt-6 grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]" role="search">
            <label class="relative">
                <span class="sr-only">Search brands</span>
                <x-plat.icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 w-4 h-4 -translate-y-1/2 text-slate2" />
                <input type="search" name="q" value="{{ $q }}" placeholder="Brand, founder, district…" maxlength="80"
                       class="w-full rounded-xl border border-hair bg-cloud py-3 pl-10 pr-3 text-[15px] focus:border-leaf focus:bg-white focus:outline-none focus:ring-2 focus:ring-leaf/20">
            </label>
            <label>
                <span class="sr-only">Category</span>
                <select name="category" class="w-full rounded-xl border border-hair bg-cloud px-3 py-3 text-[15px] focus:border-leaf focus:outline-none">
                    <option value="">All categories</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->slug }}" @selected($category?->id === $c->id)>{{ $c->name }} ({{ $c->brands_count }})</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="sr-only">Division</span>
                <select name="division" class="w-full rounded-xl border border-hair bg-cloud px-3 py-3 text-[15px] focus:border-leaf focus:outline-none">
                    <option value="">All divisions</option>
                    @foreach($divisions as $d)
                        <option value="{{ $d }}" @selected($division === $d)>{{ $d }}</option>
                    @endforeach
                </select>
            </label>
            <button class="rounded-xl bg-night px-5 py-3 text-sm font-bold text-white hover:bg-navy">Search</button>
            <label class="flex items-center gap-2 text-sm font-semibold text-slate2 sm:col-span-4">
                <input type="checkbox" name="verified" value="1" @checked(request()->boolean('verified')) onchange="this.form.submit()" class="h-4 w-4 rounded border-hair text-leaf focus:ring-leaf">
                Verified brands only
            </label>
        </form>
    </div>
</section>

<section class="{{ $wrap }} py-8 sm:py-10">
    @if($brands->isEmpty())
        <div class="rounded-[24px] border border-dashed border-hair bg-white px-6 py-14 text-center">
            <x-plat.icon name="store" class="mx-auto w-8 h-8 text-slate2" />
            <h2 class="mt-3 text-lg font-extrabold">No brands found</h2>
            <p class="mt-1 text-sm text-slate2">@if($q || $category || $division) Try a different search or clear the filters. @else The first brands are being reviewed right now. @endif</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                @if($q || $category || $division)<a href="{{ route('brands.index') }}" class="rounded-xl border border-hair px-4 py-2.5 text-sm font-bold hover:border-leaf">Clear filters</a>@endif
                <a href="{{ route('owner.register') }}" class="rounded-xl bg-leaf px-4 py-2.5 text-sm font-bold text-white hover:bg-leafdk">List your brand</a>
            </div>
        </div>
    @else
        <p class="mb-4 text-sm font-semibold text-slate2">{{ number_format($brands->total()) }} {{ Str::plural('brand', $brands->total()) }}</p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach($brands as $brand)
                <x-plat.brand-card :brand="$brand->toCard()" />
            @endforeach
        </div>
        <div class="mt-8">{{ $brands->links() }}</div>
    @endif

    <div class="mt-12 flex flex-col items-start justify-between gap-4 rounded-[24px] bg-[linear-gradient(135deg,#00513C,#0A1428)] p-6 text-white sm:flex-row sm:items-center sm:p-8">
        <div>
            <h2 class="text-xl font-extrabold">Own a Bangladeshi brand?</h2>
            <p class="mt-1 text-sm text-white/75">List it free — get a permanent profile, your own link and a chance at national awards.</p>
        </div>
        <a href="{{ route('owner.register') }}" class="shrink-0 rounded-xl bg-gold px-5 py-3 text-sm font-bold text-night hover:brightness-105">List your brand</a>
    </div>
</section>
@endsection
