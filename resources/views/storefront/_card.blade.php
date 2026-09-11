@php
    $variant = $product->cardVariant();
    $savings = $variant?->savingsAmount();
    $stock = $variant?->stockCount();
    $outOfStock = $variant && $stock !== null && $stock <= 0;
    $activeCount = $product->variants->where('is_active', 1)->count();
    // Only a genuinely single-variant product can be added to cart straight
    // from the grid — anything with real Size/Color/etc selection still
    // needs to go through the product page's attribute picker, never a
    // guessed default.
    $quickAddVariant = $activeCount === 1 ? $variant : null;
@endphp
<div class="group bg-white rounded-card border border-ink/5 overflow-hidden shadow-sm hover:shadow-md transition flex flex-col">
    <a href="{{ route('storefront.product', $product->slug) }}" class="block">
        <div class="relative aspect-square bg-ink/5 grid place-items-center text-3xl overflow-hidden">
            @if ($product->thumbnail_path)
                <img src="{{ asset('storage/' . $product->thumbnail_path) }}" class="w-full h-full object-cover" alt="{{ $product->name }}" loading="lazy">
            @else 📦 @endif

            @if ($savings)
                <span class="absolute top-2 left-2 bg-red-50 text-red-500 text-[11px] font-semibold px-2 py-0.5 rounded">Save {{ number_format($savings) }} Tk</span>
            @endif
            @if ($outOfStock)
                <span class="absolute inset-0 bg-white/70 grid place-items-center text-xs font-semibold text-ink/70">স্টক শেষ</span>
            @endif
        </div>
        <div class="p-3 pb-2">
            <p class="text-sm font-medium leading-snug line-clamp-2 min-h-[2.5em]">{{ $product->name }}</p>
            <div class="mt-1.5 flex items-baseline gap-1.5">
                <p class="font-bold text-brand">{{ $variant ? number_format($variant->selling_price) : $product->priceRange() }}৳</p>
                @if ($savings)
                    <p class="text-xs text-red-400 line-through">{{ number_format($variant->compare_at_price) }}৳</p>
                @endif
            </div>
        </div>
    </a>

    <div class="px-3 pb-3 mt-auto">
        @if ($quickAddVariant && ! $outOfStock)
            <form method="POST" action="{{ route('storefront.cart.add') }}">
                @csrf
                <input type="hidden" name="variant_id" value="{{ $quickAddVariant->id }}">
                <button type="submit" class="w-full h-8 rounded-btn border border-brand text-brand text-xs font-semibold hover:bg-brand hover:text-white transition">কার্টে যোগ করুন</button>
            </form>
        @else
            <a href="{{ route('storefront.product', $product->slug) }}"
               class="block w-full h-8 leading-8 text-center rounded-btn border {{ $outOfStock ? 'border-ink/10 text-ink/40 pointer-events-none' : 'border-brand text-brand hover:bg-brand hover:text-white' }} text-xs font-semibold transition">
                {{ $outOfStock ? 'স্টক শেষ' : 'দেখুন / অর্ডার করুন' }}
            </a>
        @endif
    </div>
</div>
