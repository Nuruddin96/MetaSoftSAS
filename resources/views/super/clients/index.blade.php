@extends('layouts.super')
@section('title', 'ক্লায়েন্ট')
@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <h1 class="font-disp font-bold text-2xl">ক্লায়েন্ট</h1>
    <a href="{{ route('super.clients.create') }}" class="px-4 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">+ নতুন ক্লায়েন্ট</a>
</div>

<div class="grid grid-cols-2 gap-4 mb-6 max-w-lg">
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট ডিউ</p><p class="font-disp font-extrabold text-2xl mt-1 text-red-600">{{ number_format($totalDue) }}৳</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট অগ্রিম</p><p class="font-disp font-extrabold text-2xl mt-1 text-leafdk">{{ number_format($totalAdv) }}৳</p></div>
</div>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="বিজনেস / নাম / ফোন..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-72">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব স্ট্যাটাস</option>
        @foreach (['active' => 'অ্যাক্টিভ', 'paused' => 'পজড', 'ended' => 'শেষ'] as $k => $v)
            <option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>
        @endforeach
    </select>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">বিজনেস</th><th class="px-4 py-3">ক্লায়েন্ট</th>
            <th class="px-4 py-3">সার্ভিস</th><th class="px-4 py-3">মাসিক চার্জ</th>
            <th class="px-4 py-3">ডিউ</th><th class="px-4 py-3">অগ্রিম</th><th class="px-4 py-3">স্ট্যাটাস</th>
        </tr></thead>
        <tbody>
        @forelse ($clients as $c)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60 cursor-pointer" onclick="window.location='{{ route('super.clients.show', $c) }}'">
                <td class="px-4 py-3 font-medium">{{ $c->business_name }}</td>
                <td class="px-4 py-3">{{ $c->client_name }}<br><span class="text-xs text-mute">{{ $c->phone }}</span></td>
                <td class="px-4 py-3 text-mute text-xs">{{ $c->service }}</td>
                <td class="px-4 py-3">{{ $c->monthly_charge ? number_format($c->monthly_charge) . '৳' : '—' }}</td>
                <td class="px-4 py-3 {{ $c->due_amount > 0 ? 'text-red-600 font-semibold' : 'text-mute' }}">{{ number_format($c->due_amount) }}৳</td>
                <td class="px-4 py-3 {{ $c->advance_amount > 0 ? 'text-leafdk font-semibold' : 'text-mute' }}">{{ number_format($c->advance_amount) }}৳</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ ['active' => 'bg-leaf/10 text-leafdk', 'paused' => 'bg-amber/15', 'ended' => 'bg-red-50 text-red-600'][$c->status] }}">{{ $c->status }}</span></td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-12 text-center text-mute">কোনো ক্লায়েন্ট নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $clients->links() }}</div>
@endsection
