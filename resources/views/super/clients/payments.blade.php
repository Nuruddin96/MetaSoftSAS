@extends('layouts.super')
@section('title', 'রেগুলার ক্লায়েন্ট পেমেন্ট')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">রেগুলার ক্লায়েন্ট পেমেন্ট</h1>

<div class="grid grid-cols-2 gap-4 mb-6 max-w-lg">
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট (৳ ফিল্টার অনুযায়ী)</p><p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($totalBdt) }}৳</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট ($ ফিল্টার অনুযায়ী)</p><p class="font-disp font-extrabold text-2xl mt-1">${{ number_format($totalUsd) }}</p></div>
</div>

<form class="flex flex-wrap gap-3 mb-4">
    <select name="client_id" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব ক্লায়েন্ট</option>
        @foreach ($clients as $c)
            <option value="{{ $c->id }}" @selected(request('client_id') == $c->id)>{{ $c->business_name }}</option>
        @endforeach
    </select>
    <select name="type" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব ধরন</option>
        @foreach (['monthly' => 'মাসিক বিল', 'ad_spend' => 'অ্যাড খরচ', 'advance' => 'অগ্রিম', 'other' => 'অন্যান্য'] as $k => $v)
            <option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>
        @endforeach
    </select>
    <select name="gateway" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব গেটওয়ে</option>
        @foreach (['bkash' => 'বিকাশ', 'nagad' => 'নগদ', 'bank' => 'ব্যাংক', 'cash' => 'ক্যাশ', 'other' => 'অন্যান্য'] as $k => $v)
            <option value="{{ $k }}" @selected(request('gateway') === $k)>{{ $v }}</option>
        @endforeach
    </select>
    <input type="date" name="from" value="{{ request('from') }}" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
    <input type="date" name="to" value="{{ request('to') }}" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
    <button class="px-4 py-2.5 rounded-lg bg-ink text-white text-sm font-semibold">ফিল্টার করুন</button>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">তারিখ</th><th class="px-4 py-3">ক্লায়েন্ট</th>
            <th class="px-4 py-3">ধরন</th><th class="px-4 py-3">গেটওয়ে</th>
            <th class="px-4 py-3">মাস</th><th class="px-4 py-3">পরিমাণ</th><th class="px-4 py-3">এটাচমেন্ট</th>
        </tr></thead>
        <tbody>
        @forelse ($payments as $p)
            <tr class="border-b border-ink/5 last:border-0">
                <td class="px-4 py-3 text-xs text-mute">{{ $p->payment_date->format('d M Y') }}</td>
                <td class="px-4 py-3">
                    <a href="{{ route('super.clients.show', $p->client_id) }}" class="text-leaf hover:underline font-medium">{{ $p->client?->business_name }}</a>
                </td>
                <td class="px-4 py-3">{{ ['monthly' => 'মাসিক বিল', 'ad_spend' => 'অ্যাড খরচ', 'advance' => 'অগ্রিম', 'other' => 'অন্যান্য'][$p->payment_type] }}</td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded bg-ink/5 text-xs">{{ strtoupper($p->gateway) }}</span></td>
                <td class="px-4 py-3 text-mute text-xs">{{ $p->month_for ?: '—' }}</td>
                <td class="px-4 py-3 font-semibold">{{ $p->currency === 'USD' ? '$' : '' }}{{ number_format($p->amount) }}{{ $p->currency === 'BDT' ? '৳' : '' }}</td>
                <td class="px-4 py-3">
                    @if ($p->attachment_path)
                        <a href="{{ asset('storage/'.$p->attachment_path) }}" target="_blank" class="text-leaf text-xs hover:underline">📎 দেখুন</a>
                    @else
                        <span class="text-xs text-mute">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-12 text-center text-mute">কোনো পেমেন্ট এন্ট্রি নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $payments->links() }}</div>
@endsection
