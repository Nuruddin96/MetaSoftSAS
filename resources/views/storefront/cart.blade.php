@extends('layouts.store')

@section('title', 'কার্ট — ' . $tenant->store_name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">আপনার কার্ট</h1>
    @if ($items->isNotEmpty())
        <form method="POST" action="{{ route('storefront.cart.clear') }}" onsubmit="return confirm('পুরো কার্ট খালি করবেন?')">
            @csrf
            <button class="text-sm text-red-600 hover:underline">কার্ট খালি করুন</button>
        </form>
    @endif
</div>

@if ($items->isEmpty())
    <div class="text-center py-20">
        <p class="text-4xl">🛒</p>
        <p class="text-mute mt-3">কার্ট খালি।</p>
        <a href="{{ route('storefront.products') }}" class="inline-block mt-4 px-5 py-2.5 rounded-lg bg-brand text-white font-semibold text-sm">কেনাকাটা শুরু করুন</a>
    </div>
@else
    <form method="POST" action="{{ route('storefront.cart.update') }}">
        @csrf
        <div class="bg-white rounded-xl border border-ink/5 divide-y divide-ink/5">
            @foreach ($items as $item)
                <div class="flex items-center gap-4 p-4">
                    <div class="w-14 h-14 rounded bg-ink/5 grid place-items-center overflow-hidden shrink-0">
                        @if ($item['variant']->product->thumbnail_path)
                            <img src="{{ asset('storage/' . $item['variant']->product->thumbnail_path) }}" class="w-full h-full object-cover">
                        @else 📦 @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate">{{ $item['variant']->product->name }}</p>
                        @if ($item['variant']->variant_name !== 'Default')
                            <p class="text-xs text-mute">{{ $item['variant']->variant_name }}</p>
                        @endif
                        <p class="text-sm text-brand font-semibold">{{ number_format($item['variant']->selling_price) }}৳</p>
                    </div>
                    <input type="number" name="qty[{{ $item['variant']->id }}]" value="{{ $item['qty'] }}" min="0" max="100"
                           class="w-16 rounded-lg border border-ink/15 px-2 py-1.5 text-center text-sm">
                    <p class="w-24 text-right font-semibold text-sm">{{ number_format($item['total']) }}৳</p>
                    <button type="submit" formaction="{{ route('storefront.cart.remove', $item['variant']->id) }}"
                            formnovalidate class="text-red-500 hover:text-red-700 text-lg leading-none px-1" title="সরান">✕</button>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mt-6">
            <button type="submit" class="text-sm text-mute hover:text-ink underline text-left">পরিমাণ আপডেট করুন</button>
            <div class="text-right">
                <p class="text-sm text-mute">সাবটোটাল: <span class="font-bold text-ink text-lg">{{ number_format($subtotal) }}৳</span></p>
                <a href="{{ route('storefront.checkout') }}"
                   class="inline-block mt-3 px-10 py-3.5 rounded-xl bg-brand text-white font-bold hover:opacity-90">চেকআউট →</a>
            </div>
        </div>
    </form>
@endif
@endsection
