@extends('layouts.super')
@section('title', 'প্রোডাক্ট সোর্স — পণ্য তালিকা')
@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">প্রোডাক্ট সোর্স — পণ্য তালিকা</h1>
    <a href="{{ route('super.source.products.create') }}" class="px-4 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">+ নতুন পণ্য</a>
</div>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">পণ্য</th><th class="px-4 py-3">দাম</th>
            <th class="px-4 py-3">ডেলিভারি</th><th class="px-4 py-3">শিপিং</th>
            <th class="px-4 py-3">স্ট্যাটাস</th><th class="px-4 py-3"></th>
        </tr></thead>
        <tbody>
        @forelse ($products as $p)
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3 flex items-center gap-3">
                    <div class="w-10 h-10 rounded bg-paper grid place-items-center overflow-hidden">
                        @if ($p->images->isNotEmpty())<img src="{{ asset('storage/'.$p->images->first()->image_path) }}" class="w-full h-full object-cover">
                        @elseif ($p->image_path)<img src="{{ asset('storage/'.$p->image_path) }}" class="w-full h-full object-cover">
                        @else 📦 @endif
                    </div>
                    <div>
                        {{ $p->name }}
                        <p class="text-xs text-mute">{{ $p->images->count() }}টি ছবি</p>
                    </div>
                </td>
                <td class="px-4 py-3">{{ $p->priceLabel() }}৳</td>
                <td class="px-4 py-3 text-mute">{{ $p->delivery_time_days ?: '—' }}</td>
                <td class="px-4 py-3">{{ number_format($p->shipping_cost) }}৳</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $p->is_active ? 'bg-leaf/10 text-leafdk' : 'bg-ink/5' }}">{{ $p->is_active ? 'চালু' : 'বন্ধ' }}</span></td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('super.source.products.edit', $p) }}" class="text-leaf text-xs hover:underline mr-3">এডিট</a>
                    <form method="POST" action="{{ route('super.source.products.destroy', $p) }}" class="inline" onsubmit="return confirm('মুছবেন?')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 text-xs hover:underline">মুছুন</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-12 text-center text-mute">কোনো পণ্য নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $products->links() }}</div>
@endsection
