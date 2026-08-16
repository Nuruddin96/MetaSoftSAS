@extends('layouts.store')

@section('title', $product->name . ' — ' . $tenant->store_name)
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 155) ?: $product->name)

@section('content')
@php
    $firstVariant = $product->variants->first();
    $gallery = $product->images->isNotEmpty() ? $product->images : collect();
    $mainImage = $product->thumbnail_path ?: $gallery->first()?->image_path;
    $discount = $firstVariant?->discountPercent();
    $stock = $firstVariant?->stockCount();
    $outOfStock = $firstVariant && $stock !== null && $stock <= 0;
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

    <div class="pb-24 md:pb-0">
        <h1 class="font-disp font-bold text-2xl">{{ $product->name }}</h1>

        <form method="POST" action="{{ route('storefront.cart.add') }}" class="mt-5 space-y-4" id="buyForm">
            @csrf
            @if ($product->variants->count() > 1)
                <div>
                    <label class="text-sm font-medium">ভ্যারিয়েন্ট বাছাই করুন</label>
                    <div class="mt-2 flex flex-wrap gap-2" id="variantPick">
                        @foreach ($product->variants as $i => $v)
                            <label class="cursor-pointer">
                                <input type="radio" name="variant_id" value="{{ $v->id }}" class="peer sr-only"
                                       data-price="{{ number_format($v->selling_price) }}"
                                       data-compare="{{ $v->compare_at_price ? number_format($v->compare_at_price) : '' }}"
                                       data-discount="{{ $v->discountPercent() ?? '' }}"
                                       data-stock="{{ $v->stockCount() }}"
                                       data-threshold="{{ $v->low_stock_threshold }}"
                                       @checked($i === 0)>
                                <span class="inline-block px-4 py-2 rounded-lg border border-ink/15 text-sm peer-checked:border-brand peer-checked:bg-brand/10 peer-checked:font-semibold">
                                    {{ $v->variant_name }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @else
                <input type="hidden" name="variant_id" value="{{ $firstVariant?->id }}">
            @endif

            <div class="flex items-baseline gap-2 flex-wrap">
                <p class="font-disp font-extrabold text-3xl text-brand" id="priceShow">{{ number_format($firstVariant?->selling_price ?? 0) }}৳</p>
                <p class="text-base text-mute line-through {{ $discount ? '' : 'hidden' }}" id="compareShow">{{ $firstVariant?->compare_at_price ? number_format($firstVariant->compare_at_price) : '' }}৳</p>
                <span class="bg-accent/20 text-ink text-xs font-bold px-2 py-0.5 rounded-md {{ $discount ? '' : 'hidden' }}" id="discountShow">-{{ $discount }}%</span>
            </div>

            <p class="text-sm {{ $outOfStock ? 'text-red-600 font-semibold' : ($stock !== null && $stock <= ($firstVariant?->low_stock_threshold ?? 5) ? 'text-amber-600' : 'text-mute') }}" id="stockShow">
                @if ($outOfStock) স্টক শেষ
                @elseif ($stock !== null && $stock <= ($firstVariant?->low_stock_threshold ?? 5)) মাত্র {{ $stock }} টি বাকি
                @else ✓ স্টকে আছে
                @endif
            </p>

            <div class="flex items-center gap-3">
                <label class="text-sm">পরিমাণ</label>
                <input type="number" name="qty" value="1" min="1" max="100"
                       class="w-20 rounded-lg border border-ink/15 px-3 py-2 text-center">
            </div>

            <button class="hidden md:block w-full md:w-auto px-10 py-3.5 rounded-btn bg-brand text-white font-bold hover:opacity-90 disabled:opacity-50" @disabled($outOfStock)>
                🛒 {{ $outOfStock ? 'স্টক নেই' : 'অর্ডার করুন' }}
            </button>
            <p class="text-xs text-mute">ক্যাশ অন ডেলিভারি — রেজিস্ট্রেশন লাগবে না</p>
        </form>

        @if ($delivery['chargeInside'] || $delivery['chargeOutside'])
            <p class="mt-4 text-sm text-mute border-t border-ink/10 pt-4">
                🚚 ঢাকার ভিতরে {{ number_format($delivery['chargeInside']) }}৳, ঢাকার বাইরে {{ number_format($delivery['chargeOutside']) }}৳ ডেলিভারি চার্জ
            </p>
        @endif

        @if ($product->description)
            <div class="mt-4 text-sm text-mute leading-relaxed whitespace-pre-line border-t border-ink/10 pt-5">{{ $product->description }}</div>
        @endif
    </div>
</div>

{{-- Mobile-only sticky buy bar — same form fields, submits the exact same #buyForm above, never a second/duplicate cart-add path. --}}
<div class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-ink/10 px-4 py-3 flex items-center gap-3" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
    <div class="min-w-0">
        <p class="font-bold text-brand text-lg leading-tight" id="priceShowMobile">{{ number_format($firstVariant?->selling_price ?? 0) }}৳</p>
    </div>
    <button type="submit" form="buyForm" class="flex-1 px-6 py-3 rounded-btn bg-brand text-white font-bold disabled:opacity-50" @disabled($outOfStock)>
        🛒 {{ $outOfStock ? 'স্টক নেই' : 'অর্ডার করুন' }}
    </button>
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

    document.querySelectorAll('#variantPick input').forEach(r =>
        r.addEventListener('change', e => {
            const d = e.target.dataset;
            document.getElementById('priceShow').textContent = d.price + '৳';
            document.getElementById('priceShowMobile').textContent = d.price + '৳';

            const compareEl = document.getElementById('compareShow');
            const discountEl = document.getElementById('discountShow');
            if (d.discount) {
                compareEl.textContent = d.compare + '৳';
                compareEl.classList.remove('hidden');
                discountEl.textContent = '-' + d.discount + '%';
                discountEl.classList.remove('hidden');
            } else {
                compareEl.classList.add('hidden');
                discountEl.classList.add('hidden');
            }

            const stock = parseInt(d.stock, 10);
            const threshold = parseInt(d.threshold, 10) || 5;
            const stockEl = document.getElementById('stockShow');
            if (stock <= 0) {
                stockEl.textContent = 'স্টক শেষ';
                stockEl.className = 'text-sm text-red-600 font-semibold';
            } else if (stock <= threshold) {
                stockEl.textContent = 'মাত্র ' + stock + ' টি বাকি';
                stockEl.className = 'text-sm text-amber-600';
            } else {
                stockEl.textContent = '✓ স্টকে আছে';
                stockEl.className = 'text-sm text-mute';
            }
        }));

    document.querySelectorAll('.thumb-btn').forEach(btn =>
        btn.addEventListener('click', () => {
            const img = document.getElementById('mainImage');
            if (img) img.src = btn.dataset.src;
        }));
</script>
@endpush
@endsection
