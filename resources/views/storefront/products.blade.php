@extends('layouts.store')

@section('title', 'সব প্রোডাক্ট — ' . $tenant->store_name)

@section('content')
@php
    $sortLabels = ['latest' => 'নতুন', 'price_asc' => 'দাম: কম থেকে বেশি', 'price_desc' => 'দাম: বেশি থেকে কম', 'name' => 'নাম'];
@endphp

<div id="search" class="mb-4">
    <form action="{{ route('storefront.products') }}" method="GET" class="flex gap-2">
        @if (request('category'))<input type="hidden" name="category" value="{{ request('category') }}">@endif
        @if (request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
        <div class="relative flex-1">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="প্রোডাক্ট খুঁজুন..."
                   class="w-full h-10 rounded-btn border border-ink/15 bg-white pl-9 pr-3 text-sm outline-none focus:border-brand focus:ring-1 focus:ring-brand">
            <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-mute"></i>
        </div>
        <button class="h-10 px-4 rounded-btn bg-brand text-white text-sm font-semibold shrink-0">খুঁজুন</button>
    </form>
</div>

{{-- Desktop: category pills + sort dropdown inline. Mobile: a single "ফিল্টার" button opening a bottom sheet with the same two controls, so the page itself stays uncluttered on small screens. --}}
<div class="hidden md:flex items-center justify-between gap-4 mb-6">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('storefront.products', array_filter(['sort' => request('sort'), 'q' => request('q')])) }}"
           class="px-3 py-1.5 rounded-btn text-sm {{ !request('category') ? 'bg-brand text-white' : 'bg-white border border-ink/10 hover:border-brand' }}">সব</a>
        @foreach ($categories as $cat)
            <a href="{{ route('storefront.products', array_filter(['category' => $cat->slug, 'sort' => request('sort'), 'q' => request('q')])) }}"
               class="px-3 py-1.5 rounded-btn text-sm {{ request('category') === $cat->slug ? 'bg-brand text-white' : 'bg-white border border-ink/10 hover:border-brand' }}">{{ $cat->name }}</a>
        @endforeach
    </div>

    <form action="{{ route('storefront.products') }}" method="GET" class="shrink-0">
        @if (request('category'))<input type="hidden" name="category" value="{{ request('category') }}">@endif
        @if (request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        <select name="sort" onchange="this.form.submit()" class="h-9 rounded-btn border border-ink/15 bg-white px-3 text-sm">
            @foreach ($sortLabels as $value => $label)
                <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="md:hidden mb-5">
    <button type="button" id="openFilterSheet" class="w-full h-10 rounded-btn border border-ink/15 bg-white text-sm font-medium flex items-center justify-center gap-2">
        <i data-lucide="sliders-horizontal" class="w-4 h-4"></i> ফিল্টার ও সর্ট
        @if (request('category') || ($sort !== 'latest'))<span class="w-1.5 h-1.5 rounded-full bg-brand"></span>@endif
    </button>
</div>

@if ($products->isEmpty())
    <p class="text-center text-mute py-20">{{ request('q') ? 'কোনো প্রোডাক্ট পাওয়া যায়নি।' : 'এই ক্যাটাগরিতে কোনো প্রোডাক্ট নেই।' }}</p>
@else
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ($products as $product)
            @include('storefront._card', ['product' => $product])
        @endforeach
    </div>
    <div class="mt-6">{{ $products->links() }}</div>
@endif

{{-- Mobile filter bottom sheet: plain fixed-position panel + backdrop, no framework — matches the rest of the storefront's vanilla-JS approach. --}}
<div id="filterSheetBackdrop" class="md:hidden fixed inset-0 bg-black/40 z-50 hidden" aria-hidden="true"></div>
<div id="filterSheet" class="md:hidden fixed inset-x-0 bottom-0 z-50 bg-white rounded-t-card p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] translate-y-full transition-transform duration-200"
     role="dialog" aria-modal="true" aria-label="ফিল্টার ও সর্ট">
    <div class="w-10 h-1 bg-ink/15 rounded-full mx-auto mb-4"></div>
    <form action="{{ route('storefront.products') }}" method="GET">
        @if (request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif

        <p class="font-semibold text-sm mb-2">ক্যাটাগরি</p>
        <div class="flex flex-wrap gap-2 mb-5">
            <label class="cursor-pointer">
                <input type="radio" name="category" value="" class="peer sr-only" @checked(!request('category'))>
                <span class="inline-block px-3 py-1.5 rounded-btn text-sm border border-ink/15 peer-checked:bg-brand peer-checked:text-white peer-checked:border-brand">সব</span>
            </label>
            @foreach ($categories as $cat)
                <label class="cursor-pointer">
                    <input type="radio" name="category" value="{{ $cat->slug }}" class="peer sr-only" @checked(request('category') === $cat->slug)>
                    <span class="inline-block px-3 py-1.5 rounded-btn text-sm border border-ink/15 peer-checked:bg-brand peer-checked:text-white peer-checked:border-brand">{{ $cat->name }}</span>
                </label>
            @endforeach
        </div>

        <p class="font-semibold text-sm mb-2">সর্ট করুন</p>
        <div class="space-y-2 mb-6">
            @foreach ($sortLabels as $value => $label)
                <label class="flex items-center gap-2 text-sm">
                    <input type="radio" name="sort" value="{{ $value }}" @checked($sort === $value)>
                    {{ $label }}
                </label>
            @endforeach
        </div>

        <button class="w-full h-11 rounded-btn bg-brand text-white font-semibold text-sm">প্রয়োগ করুন</button>
    </form>
    <button type="button" id="closeFilterSheet" class="absolute top-4 right-4 text-ink/50" aria-label="বন্ধ করুন">
        <i data-lucide="x" class="w-5 h-5"></i>
    </button>
</div>

@push('scripts')
<script>
    const sheet = document.getElementById('filterSheet');
    const backdrop = document.getElementById('filterSheetBackdrop');
    function openSheet() { sheet.classList.remove('translate-y-full'); backdrop.classList.remove('hidden'); }
    function closeSheet() { sheet.classList.add('translate-y-full'); backdrop.classList.add('hidden'); }
    document.getElementById('openFilterSheet')?.addEventListener('click', openSheet);
    document.getElementById('closeFilterSheet')?.addEventListener('click', closeSheet);
    backdrop?.addEventListener('click', closeSheet);
</script>
@endpush
@endsection
