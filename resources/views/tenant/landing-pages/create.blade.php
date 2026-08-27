@extends('layouts.panel')

@section('title', 'নতুন ল্যান্ডিং পেজ')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">নতুন ল্যান্ডিং পেজ</h1>
    <a href="{{ route('tenant.landing-pages.index') }}" class="text-sm text-mute hover:text-ink">← ফিরে যান</a>
</div>

<x-ui.card class="max-w-xl space-y-4">
    <form method="POST" action="{{ route('tenant.landing-pages.store') }}" class="space-y-4">
        @csrf

        <div>
            <label class="text-sm font-medium">পেজের নাম</label>
            <input name="title" value="{{ old('title') }}" required placeholder="যেমনঃ Brilliant Skin অফার"
                   class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            @error('title') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="text-sm font-medium">কোন প্রোডাক্ট বিক্রি করবেন</label>
            <select name="product_id" required class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <option value="">-- প্রোডাক্ট বেছে নিন --</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>{{ $product->name }} — {{ $product->priceRange() }}৳</option>
                @endforeach
            </select>
            @error('product_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            <p class="text-xs text-mute mt-1">এই পেজের চেকআউট সবসময় এই প্রোডাক্টের জন্যই কাজ করবে। পরে চাইলেও প্রোডাক্ট বদলানো যাবে না — ভুল হলে নতুন পেজ বানান।</p>
        </div>

        <x-ui.button type="submit" variant="accent" size="sm">পরবর্তী ধাপ — সেকশন সাজান</x-ui.button>
    </form>
</x-ui.card>
@endsection
