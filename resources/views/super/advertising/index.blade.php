@extends('layouts.super')
@section('title', 'অ্যাডভার্টাইজিং বিলিং')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">📢 অ্যাডভার্টাইজিং বিলিং</h1>

<form class="flex flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="স্টোরের নাম..."
           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm w-72">
    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
        <option value="">সব</option>
        <option value="active" @selected(request('status') === 'active')>মডিউল চালু</option>
        <option value="inactive" @selected(request('status') === 'inactive')>মডিউল বন্ধ / চালু হয়নি</option>
    </select>
</form>

<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">টেনেন্ট</th>
            <th class="px-4 py-3">প্ল্যান</th>
            <th class="px-4 py-3">মডিউল</th>
            <th class="px-4 py-3">ব্যালেন্স</th>
            <th class="px-4 py-3">দৈনিক বাজেট</th>
            <th class="px-4 py-3">রেট</th>
        </tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60 cursor-pointer" onclick="window.location='{{ route('super.advertising.show', $t) }}'">
                <td class="px-4 py-3 font-medium">{{ $t->store_name }}</td>
                <td class="px-4 py-3 text-mute text-xs">{{ $t->plan?->name }} @if(! $t->plan?->allow_meta_ads)<span class="text-red-500">(Ads বন্ধ)</span>@endif</td>
                <td class="px-4 py-3">
                    @if ($t->adBillingAccount?->is_active)
                        <span class="px-2 py-1 rounded text-xs bg-leaf/10 text-leafdk">চালু</span>
                    @elseif ($t->adBillingAccount)
                        <span class="px-2 py-1 rounded text-xs bg-red-50 text-red-600">বন্ধ</span>
                    @else
                        <span class="px-2 py-1 rounded text-xs bg-ink/5 text-mute">সেটআপ হয়নি</span>
                    @endif
                </td>
                <td class="px-4 py-3 {{ $t->adBillingAccount && $t->adBillingAccount->balance <= 0 ? 'text-red-600 font-semibold' : '' }}">
                    {{ $t->adBillingAccount ? '৳'.number_format($t->adBillingAccount->balance, 2) : '—' }}
                </td>
                <td class="px-4 py-3">{{ $t->adBillingAccount ? '৳'.number_format($t->adBillingAccount->daily_budget, 2) : '—' }}</td>
                <td class="px-4 py-3">{{ $t->adBillingAccount ? '৳'.number_format($t->adBillingAccount->billing_rate, 2).'/USD' : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-12 text-center text-mute">কোনো টেনেন্ট নেই।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $tenants->links() }}</div>
@endsection
