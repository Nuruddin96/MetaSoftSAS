@extends('layouts.panel')
@section('title', 'প্রোডাক্ট রিপোর্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">সবচেয়ে বেশি বিক্রি হওয়া প্রোডাক্ট</h1>
@include('tenant.reports._filter')

<x-ui.card padding="none" class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">#</th><th class="px-4 py-3">প্রোডাক্ট</th>
            <th class="px-4 py-3">বিক্রি</th><th class="px-4 py-3">আয়</th><th class="px-4 py-3">লাভ</th>
        </tr></thead>
        <tbody>
        @forelse ($top as $i => $p)
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3 text-mute">{{ $i + 1 }}</td>
                <td class="px-4 py-3">{{ $p->product_name }}
                    @if ($p->variant_name && $p->variant_name !== 'Default')<span class="text-xs text-mute">({{ $p->variant_name }})</span>@endif</td>
                <td class="px-4 py-3">{{ $p->qty }}টি</td>
                <td class="px-4 py-3">{{ number_format($p->revenue) }}৳</td>
                <td class="px-4 py-3 text-leafdk font-semibold">{{ number_format($p->profit) }}৳</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-12 text-center text-mute">এই সময়ে কোনো বিক্রি নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</x-ui.card>
@endsection
