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
        <a href="{{ route('storefront.products') }}" class="inline-block mt-4 px-5 py-2.5 rounded-btn bg-brand text-white font-semibold text-sm">কেনাকাটা শুরু করুন</a>
    </div>
@else
    <form method="POST" action="{{ route('storefront.cart.update') }}">
        @csrf
        <div class="bg-white rounded-card border border-ink/5 divide-y divide-ink/5">
            @foreach ($items as $item)
                <div class="flex items-center gap-3 sm:gap-4 p-4">
                    <div class="w-16 h-16 rounded-btn bg-ink/5 grid place-items-center overflow-hidden shrink-0">
                        @if ($item['variant']->product->thumbnail_path)
                            <img src="{{ asset('storage/' . $item['variant']->product->thumbnail_path) }}" class="w-full h-full object-cover" alt="{{ $item['variant']->product->name }}">
                        @else 📦 @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate">{{ $item['variant']->product->name }}</p>
                        @if ($item['variant']->variant_name !== 'Default')
                            <p class="text-xs text-mute mt-0.5">{{ $item['variant']->variant_name }}</p>
                        @endif
                        <p class="text-sm text-brand font-semibold mt-0.5">{{ number_format($item['variant']->selling_price) }}৳</p>
                    </div>
                    <div class="hidden sm:block text-right w-24 font-semibold text-sm">{{ number_format($item['total']) }}৳</div>
                    <input type="number" name="qty[{{ $item['variant']->id }}]" value="{{ $item['qty'] }}" min="0" max="100"
                           class="w-14 h-9 rounded-btn border border-ink/15 px-2 text-center text-sm">
                    <button type="submit" formaction="{{ route('storefront.cart.remove', $item['variant']->id) }}"
                            formnovalidate class="w-8 h-8 rounded-btn grid place-items-center text-red-500 hover:bg-red-50 shrink-0" title="সরান" aria-label="সরান">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-5">
            <button type="submit" class="text-sm text-mute hover:text-ink underline text-left w-fit">পরিমাণ আপডেট করুন</button>

            <div class="bg-white rounded-card border border-ink/5 p-4 sm:bg-transparent sm:border-0 sm:p-0 sm:text-right">
                <div class="flex justify-between sm:block text-sm">
                    <span class="text-mute">সাবটোটাল</span>
                    <span class="font-bold text-ink text-lg sm:ml-2">{{ number_format($subtotal) }}৳</span>
                </div>
                <a href="{{ route('storefront.checkout') }}"
                   class="block sm:inline-block text-center mt-3 px-10 py-3.5 rounded-btn bg-brand text-white font-bold hover:opacity-90">চেকআউট →</a>
            </div>
        </div>
    </form>
@endif
@endsection
