@extends('layouts.super')

@section('title', 'পেমেন্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">সাবস্ক্রিপশন পেমেন্ট</h1>

<form class="mb-4">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব</option>
        @foreach (['completed', 'pending', 'failed'] as $st)
            <option value="{{ $st }}" @selected(request('status') === $st)>{{ $st }}</option>
        @endforeach
    </select>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">তারিখ</th><th class="px-4 py-3">টেনেন্ট</th>
            <th class="px-4 py-3">TrxID</th><th class="px-4 py-3">গেটওয়ে</th>
            <th class="px-4 py-3">টাকা</th><th class="px-4 py-3">স্ট্যাটাস</th>
        </tr></thead>
        <tbody>
        @forelse ($payments as $p)
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3 text-xs text-mute">{{ $p->created_at->format('d M Y, h:i A') }}</td>
                <td class="px-4 py-3">{{ $tenants[$p->tenant_id]->store_name ?? '—' }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ $p->trx_id }}</td>
                <td class="px-4 py-3">{{ $p->gateway }}</td>
                <td class="px-4 py-3 font-semibold">{{ number_format($p->amount) }}৳</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ ['completed' => 'bg-leaf/10 text-leafdk', 'failed' => 'bg-red-50 text-red-600'][$p->status] ?? 'bg-amber/15' }}">{{ $p->status }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-12 text-center text-mute">কোনো পেমেন্ট নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $payments->links() }}</div>
@endsection
