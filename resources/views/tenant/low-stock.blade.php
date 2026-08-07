@extends('layouts.panel')
@section('title', 'লো স্টক')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">⚠️ লো স্টক অ্যালার্ট</h1>
<p class="text-sm text-mute mb-6">যেসব প্রোডাক্ট প্রায় শেষ — সময়মতো অর্ডার দিন।</p>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">প্রোডাক্ট</th><th class="px-4 py-3">বারকোড</th>
            <th class="px-4 py-3">বর্তমান স্টক</th><th class="px-4 py-3">অ্যালার্ট লেভেল</th>
        </tr></thead>
        <tbody>
        @forelse ($variants as $v)
            <tr class="border-b border-ink/5 last:border-0 bg-red-50/40">
                <td class="px-4 py-3"><p class="font-medium">{{ $v->product?->name }}</p><p class="text-xs text-mute">{{ $v->variant_name }}</p></td>
                <td class="px-4 py-3 font-mono text-xs">{{ $v->barcode }}</td>
                <td class="px-4 py-3 font-bold text-red-600">{{ $v->totalStock() }}</td>
                <td class="px-4 py-3 text-mute">{{ $v->low_stock_threshold }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="px-4 py-12 text-center text-mute">সব প্রোডাক্টের স্টক ঠিক আছে ✓</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="text-xs text-mute mt-4">অ্যালার্ট লেভেল বদলাতে চাইলে প্রোডাক্ট এডিট পেজে যান।</p>
@endsection
