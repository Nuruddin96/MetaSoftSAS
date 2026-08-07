@extends('layouts.panel')
@section('title', 'লাভ-ক্ষতি')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">লাভ-ক্ষতির হিসাব</h1>
@include('tenant.reports._filter')

<x-ui.card class="max-w-2xl">
    <div class="space-y-3 text-sm">
        <div class="flex justify-between"><span>মোট বিক্রি</span><span class="font-semibold">{{ number_format($revenue) }}৳</span></div>
        <div class="flex justify-between text-mute"><span>— ডেলিভারি চার্জ (কুরিয়ারের)</span><span>−{{ number_format($shipping) }}৳</span></div>
        <div class="flex justify-between text-mute"><span>— প্রোডাক্টের কেনা দাম</span><span>−{{ number_format($cogs) }}৳</span></div>
        <div class="flex justify-between border-t border-ink/10 pt-3 font-semibold"><span>গ্রস লাভ</span><span>{{ number_format($grossProfit) }}৳</span></div>
        <div class="flex justify-between text-mute"><span>— অন্যান্য খরচ</span><span>−{{ number_format($expenses) }}৳</span></div>
        <div class="flex justify-between border-t-2 border-ink/20 pt-3 font-bold text-lg {{ $netProfit >= 0 ? 'text-leafdk' : 'text-red-600' }}">
            <span>নিট {{ $netProfit >= 0 ? 'লাভ' : 'ক্ষতি' }}</span><span>{{ number_format(abs($netProfit)) }}৳</span>
        </div>
    </div>

    @if ($expenseBreakdown->isNotEmpty())
        <div class="mt-6 pt-5 border-t border-ink/10">
            <p class="font-bold text-sm mb-2">খরচের বিভাজন</p>
            @foreach ($expenseBreakdown as $e)
                <div class="flex justify-between text-sm py-1">
                    <span class="text-mute">{{ $e->category?->name ?? 'অন্যান্য' }}</span>
                    <span>{{ number_format($e->total) }}৳</span>
                </div>
            @endforeach
        </div>
    @endif

    <p class="text-xs text-mute mt-5">💡 সঠিক হিসাবের জন্য প্রতিটি প্রোডাক্টে "কেনা দাম" দেওয়া থাকতে হবে, আর সব খরচ খরচ পেজে এন্ট্রি করতে হবে।</p>
</x-ui.card>
@endsection
