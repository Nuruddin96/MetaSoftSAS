@php
    $variant = $product->cardVariant();
    $discount = $variant?->discountPercent();
    $stock = $variant?->stockCount();
    $outOfStock = $variant && $stock !== null && $stock <= 0;
@endphp
<a href="{{ route('storefront.product', $product->slug) }}" class="group bg-white rounded-card border border-ink/5 overflow-hidden shadow-sm hover:shadow-md transition">
    <div class="relative aspect-square bg-ink/5 grid place-items-center text-3xl overflow-hidden">
        @if ($product->thumbnail_path)
            <img src="{{ asset('storage/' . $product->thumbnail_path) }}" class="w-full h-full object-cover" alt="{{ $product->name }}" loading="lazy">
        @else 📦 @endif

        @if ($discount)
            <span class="absolute top-2 left-2 bg-accent text-ink text-[11px] font-bold px-2 py-0.5 rounded-md">-{{ $discount }}%</span>
        @endif
        @if ($outOfStock)
            <span class="absolute inset-0 bg-white/70 grid place-items-center text-xs font-semibold text-ink/70">স্টক শেষ</span>
        @endif
    </div>
    <div class="p-3">
        <p class="text-sm font-medium leading-snug line-clamp-2">{{ $product->name }}</p>
        <div class="mt-1.5 flex items-baseline gap-1.5">
            <p class="font-bold text-brand">{{ $product->priceRange() }}৳</p>
            @if ($discount && $variant->compare_at_price)
                <p class="text-xs text-mute line-through">{{ number_format($variant->compare_at_price) }}৳</p>
            @endif
        </div>
    </div>
</a>
