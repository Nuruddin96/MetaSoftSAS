@extends('layouts.super')
@section('title', 'অ্যাফিলিয়েট')
@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">অ্যাফিলিয়েট</h1>
    <a href="{{ route('super.affiliates.create') }}" class="px-4 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">+ নতুন অ্যাফিলিয়েট</a>
</div>

<form class="mb-4"><input name="q" value="{{ request('q') }}" placeholder="নাম / ইমেইল / কোড..."
    class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-72"></form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">নাম</th><th class="px-4 py-3">কোড</th>
            <th class="px-4 py-3">রেফার</th><th class="px-4 py-3">লিড</th>
            <th class="px-4 py-3">অপেক্ষমান কমিশন</th><th class="px-4 py-3">স্ট্যাটাস</th>
        </tr></thead>
        <tbody>
        @forelse ($affiliates as $a)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60 cursor-pointer" onclick="window.location='{{ route('super.affiliates.show', $a) }}'">
                <td class="px-4 py-3">{{ $a->name }}<br><span class="text-xs text-mute">{{ $a->email }}</span></td>
                <td class="px-4 py-3 font-mono text-xs">{{ $a->referral_code }}</td>
                <td class="px-4 py-3">{{ $a->referred_tenants_count }}</td>
                <td class="px-4 py-3">{{ $a->service_leads_count }}</td>
                <td class="px-4 py-3 font-semibold text-amber">{{ number_format($a->pending_sum ?? 0) }}৳</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $a->status === 'active' ? 'bg-leaf/10 text-leafdk' : 'bg-red-50 text-red-600' }}">{{ $a->status }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-12 text-center text-mute">কোনো অ্যাফিলিয়েট নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $affiliates->links() }}</div>
@endsection
