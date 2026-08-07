@extends('layouts.panel')

@section('title', 'প্রোডাক্ট')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">প্রোডাক্ট</h1>
    <x-ui.button href="{{ route('tenant.products.create') }}" variant="accent" size="sm">+ নতুন প্রোডাক্ট</x-ui.button>
</div>

<form class="mb-4"><input name="q" value="{{ request('q') }}" placeholder="প্রোডাক্ট খুঁজুন..."
    class="w-full md:w-72 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none"></form>

<x-ui.card padding="none" class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">প্রোডাক্ট</th><th class="px-4 py-3">দাম</th>
            <th class="px-4 py-3">স্টক</th><th class="px-4 py-3">স্ট্যাটাস</th><th class="px-4 py-3 text-right">অ্যাকশন</th>
        </tr></thead>
        <tbody>
        @forelse ($products as $product)
            @php $stock = $product->variants->sum(fn ($v) => $v->inventory->sum('quantity')); @endphp
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-paper grid place-items-center overflow-hidden shrink-0 text-mute">
                            @if ($product->thumbnail_path)
                                <img src="{{ asset('storage/'.$product->thumbnail_path) }}" class="w-full h-full object-cover" loading="lazy">
                            @else
                                <i data-lucide="package" class="w-4.5 h-4.5"></i>
                            @endif
                        </div>
                        <div>
                            <p class="font-medium">{{ $product->name }}</p>
                            <p class="text-xs text-mute">{{ $product->category?->name ?? 'ক্যাটাগরি নেই' }} · {{ $product->variants->count() }}টি ভ্যারিয়েন্ট</p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3">{{ $product->priceRange() }}৳</td>
                <td class="px-4 py-3">
                    <span class="{{ $stock <= 5 ? 'text-red-600 font-semibold' : '' }}">{{ $stock }}</span>
                </td>
                <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-pill text-xs font-semibold {{ $product->is_active ? 'bg-leaf/10 text-leafdk' : 'bg-ink/5 text-mute' }}">{{ $product->is_active ? 'অ্যাক্টিভ' : 'বন্ধ' }}</span></td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <a href="{{ route('tenant.products.barcodes', $product) }}" target="_blank" class="inline-flex items-center gap-1 text-mute hover:text-ink text-xs mr-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                        <i data-lucide="tag" class="w-3.5 h-3.5"></i> বারকোড
                    </a>
                    <a href="{{ route('tenant.products.edit', $product) }}" class="text-leaf hover:underline text-xs mr-3 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">এডিট</a>
                    <form method="POST" action="{{ route('tenant.products.destroy', $product) }}" class="inline" onsubmit="return confirm('মুছে ফেলবেন?')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 hover:underline text-xs rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">মুছুন</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-14 text-center text-mute">
                <i data-lucide="package-open" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
                কোনো প্রোডাক্ট নেই। <a href="{{ route('tenant.products.create') }}" class="text-leaf font-semibold hover:underline">প্রথম প্রোডাক্ট যোগ করুন</a>
            </td></tr>
        @endforelse
        </tbody>
    </table>
</x-ui.card>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
