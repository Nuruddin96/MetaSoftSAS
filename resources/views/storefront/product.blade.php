@extends('layouts.store')

@section('title', $product->name . ' — ' . $tenant->store_name)
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 155) ?: $product->name)

@section('content')
@php
    $firstVariant = $product->variants->first();
    $gallery = $product->images->isNotEmpty() ? $product->images : collect();
    $mainImage = $product->thumbnail_path ?: $gallery->first()?->image_path;
    $outOfStock = $firstVariant && $firstVariant->stockCount() !== null && $firstVariant->stockCount() <= 0;
@endphp

@if ($mainImage)
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org/',
    '@type' => 'Product',
    'name' => $product->name,
    'image' => asset('storage/' . $mainImage),
    'description' => $product->description,
    'sku' => $firstVariant?->sku,
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => 'BDT',
        'price' => (string) ($firstVariant?->selling_price ?? 0),
        'availability' => $outOfStock ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
        'url' => route('storefront.product', $product->slug),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endif

<div class="grid md:grid-cols-2 gap-8">
    <div>
        <div class="aspect-square bg-white rounded-card border border-ink/5 grid place-items-center text-6xl overflow-hidden" id="mainImageWrap">
            @if ($mainImage)
                <img src="{{ asset('storage/' . $mainImage) }}" class="w-full h-full object-cover" alt="{{ $product->name }}" id="mainImage">
            @else 📦 @endif
        </div>

        @if ($gallery->isNotEmpty())
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                @foreach ($gallery as $img)
                    <button type="button" class="thumb-btn shrink-0 w-16 h-16 rounded-lg border border-ink/10 overflow-hidden hover:border-brand"
                            data-src="{{ asset('storage/' . $img->image_path) }}">
                        <img src="{{ asset('storage/' . $img->image_path) }}" class="w-full h-full object-cover" alt="{{ $product->name }}" loading="lazy">
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $product->name }}</h1>

        <form method="POST" action="{{ route('storefront.cart.add') }}" class="mt-5 space-y-4" id="buyForm">
            @csrf
            @include('storefront.partials.product-buy-widget', ['product' => $product])

            <div class="flex flex-col sm:flex-row gap-3">
                <button type="submit" id="buyBtn" name="redirect" value=""
                        class="flex-1 h-12 rounded-btn border border-brand text-brand font-bold hover:bg-brand/5 disabled:opacity-50 disabled:pointer-events-none flex items-center justify-center gap-2" @disabled($outOfStock)>
                    <i data-lucide="shopping-cart" class="w-4 h-4"></i> <span id="buyBtnLabel" data-available-label="কার্টে যোগ করুন">{{ $outOfStock ? 'স্টক নেই' : 'কার্টে যোগ করুন' }}</span>
                </button>
                <button type="submit" id="buyNowBtn" name="redirect" value="checkout" formnovalidate
                        class="flex-1 h-12 rounded-btn bg-brand text-white font-bold hover:opacity-90 disabled:opacity-50 disabled:pointer-events-none" @disabled($outOfStock)>
                    এখনই কিনুন
                </button>
            </div>
        </form>

        @if ($delivery['chargeInside'] || $delivery['chargeOutside'])
            <div class="mt-5 border-t border-ink/10 pt-4 space-y-1.5 text-sm text-mute">
                <p class="flex items-center gap-2"><i data-lucide="truck" class="w-4 h-4 shrink-0"></i> ঢাকার ভিতরে {{ number_format($delivery['chargeInside']) }}৳, ঢাকার বাইরে {{ number_format($delivery['chargeOutside']) }}৳ ডেলিভারি চার্জ</p>
                <p class="flex items-center gap-2"><i data-lucide="banknote" class="w-4 h-4 shrink-0"></i> পেমেন্ট: শুধুমাত্র ক্যাশ অন ডেলিভারি</p>
            </div>
        @endif

        @if ($product->description)
            <div class="mt-4 bg-white rounded-card border border-ink/5 p-4 text-sm text-mute leading-relaxed whitespace-pre-line">{{ $product->description }}</div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    document.getElementById('buyForm')?.addEventListener('submit', () => {
        if (typeof fbq === 'function') {
            fbq('track', 'AddToCart', {
                content_name: @json($product->name),
                content_type: 'product',
                currency: 'BDT',
                value: parseFloat(document.getElementById('priceShow').textContent.replace(/[^\d.]/g, '')) || 0,
            });
        }
    });

    document.querySelectorAll('.thumb-btn').forEach(btn =>
        btn.addEventListener('click', () => {
            const img = document.getElementById('mainImage');
            if (img) img.src = btn.dataset.src;
        }));
</script>
@endpush
@endsection
