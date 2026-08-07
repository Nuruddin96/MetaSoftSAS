<a href="{{ route('storefront.product', $product->slug) }}" class="bg-white rounded-xl border border-ink/5 overflow-hidden hover:shadow-md transition">
    <div class="aspect-square bg-ink/5 grid place-items-center text-3xl overflow-hidden">
        @if ($product->thumbnail_path)
            <img src="{{ asset('storage/' . $product->thumbnail_path) }}" class="w-full h-full object-cover" alt="{{ $product->name }}">
        @else 📦 @endif
    </div>
    <div class="p-3">
        <p class="text-sm font-medium leading-snug">{{ $product->name }}</p>
        <p class="font-bold mt-1 text-brand">{{ $product->priceRange() }}৳</p>
    </div>
</a>
