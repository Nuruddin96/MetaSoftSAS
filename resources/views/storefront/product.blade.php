@extends('layouts.store')

@section('title', $product->name . ' — ' . $tenant->store_name)

@section('content')
<div class="grid md:grid-cols-2 gap-8">
    <div class="aspect-square bg-white rounded-2xl border border-ink/5 grid place-items-center text-6xl overflow-hidden">
        @if ($product->thumbnail_path)
            <img src="{{ asset('storage/' . $product->thumbnail_path) }}" class="w-full h-full object-cover" alt="{{ $product->name }}">
        @else 📦 @endif
    </div>

    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $product->name }}</h1>

        <form method="POST" action="{{ route('storefront.cart.add') }}" class="mt-5 space-y-4">
            @csrf
            @if ($product->variants->count() > 1)
                <div>
                    <label class="text-sm font-medium">ভ্যারিয়েন্ট বাছাই করুন</label>
                    <div class="mt-2 flex flex-wrap gap-2" id="variantPick">
                        @foreach ($product->variants as $i => $v)
                            <label class="cursor-pointer">
                                <input type="radio" name="variant_id" value="{{ $v->id }}" class="peer sr-only"
                                       data-price="{{ number_format($v->selling_price) }}" @checked($i === 0)>
                                <span class="inline-block px-4 py-2 rounded-lg border border-ink/15 text-sm peer-checked:border-brand peer-checked:bg-brand/10 peer-checked:font-semibold">
                                    {{ $v->variant_name }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @else
                <input type="hidden" name="variant_id" value="{{ $product->variants->first()->id }}">
            @endif

            <p class="font-disp font-extrabold text-3xl text-brand" id="priceShow">{{ number_format($product->variants->first()->selling_price) }}৳</p>

            <div class="flex items-center gap-3">
                <label class="text-sm">পরিমাণ</label>
                <input type="number" name="qty" value="1" min="1" max="100"
                       class="w-20 rounded-lg border border-ink/15 px-3 py-2 text-center">
            </div>

            <button class="w-full md:w-auto px-10 py-3.5 rounded-xl bg-brand text-white font-bold hover:opacity-90">
                🛒 অর্ডার করুন
            </button>
            <p class="text-xs text-mute">ক্যাশ অন ডেলিভারি — রেজিস্ট্রেশন লাগবে না</p>
        </form>

        @if ($product->description)
            <div class="mt-8 text-sm text-mute leading-relaxed whitespace-pre-line border-t border-ink/10 pt-5">{{ $product->description }}</div>
        @endif
    </div>
</div>

@push('scripts')
<script>
    document.querySelector('form[action*="cart/add"]')?.addEventListener('submit', () => {
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
        r.addEventListener('change', e =>
            document.getElementById('priceShow').textContent = e.target.dataset.price + '৳'));
</script>
@endpush
@endsection
