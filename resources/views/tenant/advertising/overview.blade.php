@extends('layouts.panel')
@section('title', 'অ্যাডভার্টাইজিং — ওভারভিউ')
@section('content')
@php
    $status = $account->balance <= 0 ? 'suspended' : ($account->balance <= $account->low_balance_threshold ? 'low' : 'active');
    $statusMeta = [
        'active' => ['bg-leaf/10 text-leafdk', '✅ সচল'],
        'low' => ['bg-amber/15 text-ink', '⚠️ ব্যালেন্স কম'],
        'suspended' => ['bg-red-50 text-red-600', '⛔ ব্যালেন্স শেষ'],
    ][$status];
@endphp

<h1 class="font-disp font-bold text-2xl mb-6">📢 অ্যাডভার্টাইজিং — ওভারভিউ</h1>

@if ($status === 'suspended')
    <div class="rounded-card border border-red-200 bg-red-50 text-red-700 p-4 text-sm mb-6">
        আপনার অ্যাডভার্টাইজিং ব্যালেন্স শেষ হয়ে গেছে। বিজ্ঞাপন চালু রাখতে <a href="{{ route('tenant.advertising.payments') }}" class="font-semibold underline">পেমেন্ট করুন</a>।
    </div>
@elseif ($status === 'low')
    <x-ui.card tone="amber" padding="sm" class="text-sm mb-6">
        আপনার অ্যাডভার্টাইজিং ব্যালেন্স কমে যাচ্ছে। সময়মতো পেমেন্ট করুন যাতে সার্ভিস বন্ধ না হয়।
    </x-ui.card>
@endif

<div class="grid sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">বর্তমান ব্যালেন্স</p>
        <p class="font-disp font-extrabold text-2xl mt-1">৳{{ number_format($account->balance, 2) }}</p>
        <span class="inline-block mt-2 text-xs px-2.5 py-1 rounded-pill font-semibold {{ $statusMeta[0] }}">{{ $statusMeta[1] }}</span>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">দৈনিক বাজেট</p>
        <p class="font-disp font-extrabold text-2xl mt-1">৳{{ number_format($account->daily_budget, 2) }}<span class="text-sm font-normal text-mute">/দিন</span></p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">বিলিং রেট</p>
        <p class="font-disp font-extrabold text-2xl mt-1">৳{{ number_format($account->billing_rate, 2) }}<span class="text-sm font-normal text-mute">/USD</span></p>
    </div>
</div>

<x-ui.card padding="none">
    <div class="px-5 py-4 border-b border-ink/5 flex items-center justify-between">
        <p class="font-bold text-sm">সাম্প্রতিক লেনদেন</p>
        <a href="{{ route('tenant.advertising.ledger') }}" class="text-xs text-leafdk font-semibold hover:underline">সব লেনদেন দেখুন →</a>
    </div>
    <div class="divide-y divide-ink/5">
        @forelse ($recent as $entry)
            @php
                $typeMeta = [
                    'payment' => ['+', 'text-leafdk', 'পেমেন্ট'],
                    'charge' => ['−', 'text-red-600', 'চার্জ'],
                    'adjustment_credit' => ['+', 'text-leafdk', 'অ্যাডজাস্টমেন্ট'],
                    'adjustment_debit' => ['−', 'text-red-600', 'অ্যাডজাস্টমেন্ট'],
                ][$entry->type];
            @endphp
            <div class="flex items-center justify-between px-5 py-3 text-sm">
                <div>
                    <p class="font-medium">{{ $typeMeta[2] }}</p>
                    <p class="text-xs text-mute">{{ $entry->note ?: '—' }} · {{ $entry->created_at->timezone(config('advertising.timezone'))->format('d M, h:i A') }}</p>
                </div>
                <p class="font-semibold {{ $typeMeta[1] }}">{{ $typeMeta[0] }}৳{{ number_format($entry->amount, 2) }}</p>
            </div>
        @empty
            <p class="px-5 py-10 text-center text-mute text-sm">এখনো কোনো লেনদেন হয়নি।</p>
        @endforelse
    </div>
</x-ui.card>
@endsection
