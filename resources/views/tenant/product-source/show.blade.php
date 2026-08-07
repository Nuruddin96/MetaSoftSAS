@extends('layouts.panel')
@section('title', $product->name)
@section('content')
<a href="{{ route('tenant.product-source.index') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← সব পণ্য</a>

<div class="grid md:grid-cols-2 gap-8 mt-4 max-w-4xl">
    {{-- gallery --}}
    <div>
        @php $images = $product->images->isNotEmpty() ? $product->images : ($product->image_path ? collect([(object)['image_path' => $product->image_path]]) : collect()); @endphp
        <div class="aspect-square bg-white rounded-card border border-ink/5 grid place-items-center overflow-hidden text-mute">
            @if ($images->isNotEmpty())
                <img id="mainImage" src="{{ asset('storage/'.$images->first()->image_path) }}" class="w-full h-full object-cover">
            @else
                <i data-lucide="package" class="w-12 h-12"></i>
            @endif
        </div>
        @if ($images->count() > 1)
            <div class="flex gap-2 mt-3 flex-wrap">
                @foreach ($images as $img)
                    <button onclick="document.getElementById('mainImage').src = '{{ asset('storage/'.$img->image_path) }}'"
                            class="w-16 h-16 rounded-btn overflow-hidden border border-ink/10 hover:border-leaf transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                        <img src="{{ asset('storage/'.$img->image_path) }}" class="w-full h-full object-cover" loading="lazy">
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- details + order form --}}
    <div>
        <h1 class="font-disp font-bold text-xl">{{ $product->name }}</h1>
        <p class="font-bold text-2xl text-leaf mt-2">{{ $product->priceLabel() }}৳ <span class="text-sm text-mute font-normal">/ইউনিট</span></p>

        <div class="flex flex-wrap gap-4 mt-3 text-sm text-mute">
            @if ($product->delivery_time_days)<span>⏱ ডেলিভারি: {{ $product->delivery_time_days }}</span>@endif
            @if ($product->shipping_cost > 0)<span>🚚 শিপিং: {{ number_format($product->shipping_cost) }}৳</span>@endif
            <span>📦 সর্বনিম্ন অর্ডার: {{ $product->min_order_qty }}টি</span>
        </div>

        @if ($product->description)
            <div class="mt-5 pt-5 border-t border-ink/10">
                <p class="font-bold text-sm mb-2">বর্ণনা</p>
                <p class="text-sm text-mute whitespace-pre-line">{{ $product->description }}</p>
            </div>
        @endif

        <x-ui.card class="mt-6">
            <p class="font-bold text-sm mb-3">অর্ডার করুন</p>
            @if (session('success'))
                <p class="mb-3 bg-leaf/10 border border-leaf/30 text-leafdk text-sm rounded-btn p-3">{{ session('success') }}</p>
            @endif
            @if ($errors->any())
                <p class="mb-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-btn p-3">{{ $errors->first() }}</p>
            @endif
            <form method="POST" action="{{ route('tenant.product-source.order', $product) }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs text-mute">পরিমাণ</label>
                    <input name="quantity" type="number" required value="{{ $product->min_order_qty }}" min="{{ $product->min_order_qty }}"
                           class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
                </div>
                <div>
                    <label class="text-xs text-mute">যোগাযোগের নাম্বার *</label>
                    <input name="contact_phone" required placeholder="01XXXXXXXXX" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
                </div>
                <div>
                    <label class="text-xs text-mute">নোট (ঐচ্ছিক)</label>
                    <textarea name="note" rows="2" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none"></textarea>
                </div>
                <x-ui.button type="submit" variant="accent" class="w-full">অর্ডার পাঠান</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
