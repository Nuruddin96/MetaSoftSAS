@extends('layouts.store')

@section('content')
@php
    $s = \App\Models\StoreSetting::pluck('value', 'key');
    $heroStyle = $s['hero_style'] ?? 'slider';
    $showCats  = ($s['show_categories'] ?? '1') === '1';
    $showFeat  = ($s['show_featured'] ?? '1') === '1';
    $slides    = $heroStyle === 'simple' ? $banners->take(1) : $banners;
@endphp

@if ($heroStyle !== 'none' && $banners->isNotEmpty())
    <div class="relative rounded-2xl overflow-hidden mb-6" id="heroWrap">
        @foreach ($slides as $i => $b)
            <div class="hero-slide {{ $i > 0 ? 'hidden' : '' }} relative">
                <img src="{{ asset('storage/' . $b->image_path) }}" alt="{{ $b->title }}"
                     class="w-full aspect-[16/7] md:aspect-[16/6] object-cover">
                @if ($b->title || $b->subtitle || $b->button_text)
                    <div class="absolute inset-0 bg-gradient-to-r from-black/60 to-transparent flex items-center">
                        <div class="px-6 md:px-12 max-w-lg text-white">
                            @if ($b->title)<h2 class="font-disp font-bold text-xl md:text-3xl">{{ $b->title }}</h2>@endif
                            @if ($b->subtitle)<p class="mt-2 text-sm md:text-base text-white/90">{{ $b->subtitle }}</p>@endif
                            @if ($b->button_text)
                                <a href="{{ $b->button_link ?: route('storefront.products') }}"
                                   class="inline-block mt-4 px-6 py-2.5 rounded-xl bg-brand text-white font-semibold text-sm">{{ $b->button_text }}</a>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endforeach

        @if ($heroStyle === 'slider' && $slides->count() > 1)
            <div class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-2" id="heroDots">
                @foreach ($slides as $i => $b)
                    <button data-i="{{ $i }}" class="w-2.5 h-2.5 rounded-full {{ $i === 0 ? 'bg-white' : 'bg-white/50' }}"></button>
                @endforeach
            </div>
        @endif
    </div>
@endif

{{--
    Offer section — real discounted products only (never fabricated), same
    card component and same header+grid pattern as the featured-products
    section below (title + "সব দেখুন" link, plain grid, no carousel/marquee
    chrome). Capped to 2 products here; the "সব দেখুন" link goes to the full
    filtered listing (storefront.products?offer=1).
--}}
@if ($offers->isNotEmpty())
    <div class="mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-disp font-bold text-xl">অফার পন্য</h2>
            <a href="{{ route('storefront.products', ['offer' => 1]) }}" class="text-sm text-brand hover:underline">সব দেখুন →</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ($offers->take(4) as $product)
                @include('storefront._card', ['product' => $product])
            @endforeach
        </div>
    </div>
@endif

@if ($featured->isEmpty())
    <div class="text-center py-20">
        <p class="text-5xl">🛍️</p>
        <h1 class="font-disp font-bold text-2xl mt-4">{{ $tenant->store_name }}</h1>
        <p class="text-mute mt-2">শপ সাজানো হচ্ছে — খুব শিগগিরই প্রোডাক্ট আসছে!</p>
    </div>
@elseif ($showFeat)
    <div class="flex items-center justify-between mb-6">
        <h2 class="font-disp font-bold text-xl">{{ $s['featured_title'] ?? 'আমাদের প্রোডাক্ট' }}</h2>
        <a href="{{ route('storefront.products') }}" class="text-sm text-brand hover:underline">সব দেখুন →</a>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ($featured as $product)
            @include('storefront._card', ['product' => $product])
        @endforeach
    </div>
@endif

@if ($reviews->isNotEmpty())
    <div class="mt-6">
        <h2 class="font-disp font-bold text-xl mb-4">কাস্টমার রিভিউ</h2>
        {{-- 2-per-row on both mobile and desktop per spec — not the usual responsive breakpoint jump. --}}
        <div class="grid grid-cols-2 gap-4">
            @foreach ($reviews as $review)
                <div class="bg-white rounded-card border border-ink/5 p-4">
                    <div class="flex items-center gap-3">
                        @if ($review->photo_path)
                            <img src="{{ asset('storage/'.$review->photo_path) }}" alt="{{ $review->customer_name }}" class="w-10 h-10 rounded-full object-cover shrink-0">
                        @else
                            <div class="w-10 h-10 rounded-full bg-brand/10 text-brand font-bold grid place-items-center shrink-0">{{ mb_substr($review->customer_name, 0, 1) }}</div>
                        @endif
                        <p class="font-semibold text-sm truncate">{{ $review->customer_name }}</p>
                    </div>
                    @if ($review->review_text)
                        <p class="text-xs text-mute mt-2 line-clamp-3">{{ $review->review_text }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="grid grid-cols-3 gap-3 mt-6 text-center">
    <div class="bg-white rounded-card border border-ink/5 py-4 px-2">
        <p class="text-lg">✅</p>
        <p class="text-xs text-mute mt-1">ক্যাশ অন ডেলিভারি</p>
    </div>
    <div class="bg-white rounded-card border border-ink/5 py-4 px-2">
        <p class="text-lg">🚚</p>
        <p class="text-xs text-mute mt-1">সারাদেশে ডেলিভারি</p>
    </div>
    <div class="bg-white rounded-card border border-ink/5 py-4 px-2">
        <p class="text-lg">🔒</p>
        <p class="text-xs text-mute mt-1">নিরাপদ অর্ডার</p>
    </div>
</div>

@push('scripts')
<script>
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('#heroDots button');
    let cur = 0;

    function show(i) {
        slides.forEach((s, n) => s.classList.toggle('hidden', n !== i));
        dots.forEach((d, n) => d.className = 'w-2.5 h-2.5 rounded-full ' + (n === i ? 'bg-white' : 'bg-white/50'));
        cur = i;
    }

    dots.forEach(d => d.addEventListener('click', () => show(parseInt(d.dataset.i))));
    if (slides.length > 1) setInterval(() => show((cur + 1) % slides.length), 5000);
</script>
@endpush
@endsection
