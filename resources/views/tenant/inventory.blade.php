@extends('layouts.panel')

@section('title', 'ইনভেন্টরি')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">ইনভেন্টরি</h1>

<form class="mb-4"><input name="q" value="{{ request('q') }}" placeholder="প্রোডাক্ট খুঁজুন..."
    class="w-full md:w-72 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none"></form>

<x-ui.card padding="none" class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">প্রোডাক্ট / ভ্যারিয়েন্ট</th>
            <th class="px-4 py-3">বারকোড</th>
            <th class="px-4 py-3">স্টক</th>
            <th class="px-4 py-3">স্টক যোগ/বিয়োগ</th>
        </tr></thead>
        <tbody>
        @forelse ($variants as $v)
            @php $stock = $v->inventory->sum('quantity'); @endphp
            <tr class="border-b border-ink/5 last:border-0 {{ $v->isLowStock() ? 'bg-red-50/50' : '' }}">
                <td class="px-4 py-3">
                    <p class="font-medium">{{ $v->product?->name }}</p>
                    <p class="text-xs text-mute">{{ $v->variant_name }} · {{ $v->sku }}</p>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ $v->barcode }}</td>
                <td class="px-4 py-3">
                    <span class="font-semibold {{ $v->isLowStock() ? 'text-red-600' : '' }}">{{ $stock }}</span>
                    @if ($v->isLowStock())
                        <span class="ml-1.5 inline-flex items-center gap-1 text-xs text-red-600 bg-red-50 px-2 py-0.5 rounded-pill">
                            <i data-lucide="triangle-alert" class="w-3 h-3"></i> লো স্টক
                        </span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <form method="POST" action="{{ route('tenant.inventory.adjust') }}" class="flex gap-2">
                        @csrf
                        <input type="hidden" name="variant_id" value="{{ $v->id }}">
                        <select name="warehouse_id" class="rounded-btn border border-ink/15 px-2 py-1 text-xs bg-white">
                            @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                        </select>
                        <input name="quantity" type="number" required placeholder="+10 / -2"
                               class="w-20 rounded-btn border border-ink/15 px-2 py-1 text-xs">
                        <button class="px-3 py-1 rounded-btn bg-ink text-white text-xs hover:bg-ink/90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">ঠিক আছে</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-14 text-center text-mute">
                <i data-lucide="warehouse" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
                কোনো প্রোডাক্ট নেই।
            </td></tr>
        @endforelse
        </tbody>
    </table>
</x-ui.card>
<div class="mt-4">{{ $variants->links() }}</div>
@endsection
