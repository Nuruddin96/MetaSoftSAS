@extends('layouts.panel')

@section('title', $customer->name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $customer->name }}</h1>
        <p class="text-mute text-sm"><a href="tel:{{ $customer->phone }}" class="text-leaf font-medium">{{ $customer->phone }}</a>
            @if ($customer->address) · {{ $customer->address }} @endif</p>
    </div>
    <a href="{{ route('tenant.customers.index') }}" class="text-sm text-mute hover:text-ink">← সব কাস্টমার</a>
</div>

<div class="grid md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট অর্ডার</p><p class="font-disp font-extrabold text-2xl mt-1">{{ $customer->total_orders }}</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট কেনাকাটা</p><p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($customer->total_spent) }}৳</p></div>
    <div class="bg-white rounded-xl border {{ $customer->due_balance > 0 ? 'border-red-200 bg-red-50/50' : 'border-ink/5' }} p-5">
        <p class="text-mute text-xs">বাকি</p>
        <p class="font-disp font-extrabold text-2xl mt-1 {{ $customer->due_balance > 0 ? 'text-red-600' : '' }}">{{ number_format($customer->due_balance) }}৳</p>
    </div>
</div>

<div class="grid md:grid-cols-2 gap-5 mb-6 max-w-2xl">
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="font-bold text-sm mb-1">➕ বাকি যোগ করুন</p>
        <p class="text-xs text-mute mb-3">আগের কোনো বাকি এন্ট্রি করতে বা নতুন বাকি যোগ করতে</p>
        <form method="POST" action="{{ route('tenant.customers.due.add', $customer) }}" class="space-y-2">
            @csrf
            <input name="amount" type="number" min="1" required placeholder="টাকার পরিমাণ"
                   class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <input name="note" placeholder="নোট (ঐচ্ছিক)" class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            <button class="w-full py-2.5 rounded-lg bg-amber text-ink font-semibold text-sm hover:opacity-90">বাকি যোগ করুন</button>
        </form>
    </div>

    @if ($customer->due_balance > 0)
        <div class="bg-white rounded-xl border border-ink/5 p-5">
            <p class="font-bold text-sm mb-3">📒 বাকি আদায় করুন</p>
            <form method="POST" action="{{ route('tenant.customers.due.receive', $customer) }}" class="flex gap-3">
                @csrf
                <input name="amount" type="number" min="1" max="{{ $customer->due_balance }}" required placeholder="টাকার পরিমাণ"
                       class="flex-1 rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">আদায়</button>
            </form>
        </div>
    @endif
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">সাম্প্রতিক অর্ডার</div>
        @forelse ($orders as $order)
            <a href="{{ route('tenant.orders.show', $order) }}" class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 hover:bg-paper/60 text-sm">
                <span>{{ $order->order_number }} <span class="text-xs text-mute">({{ $order->source }})</span></span>
                <span>{{ number_format($order->total) }}৳</span>
            </a>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">কোনো অর্ডার নেই।</p>
        @endforelse
    </div>

    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">বাকির খাতা</div>
        @forelse ($ledger as $entry)
            <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                <div>
                    <span class="{{ $entry->type === 'due' ? 'text-red-600' : 'text-leafdk' }} font-medium">
                        {{ $entry->type === 'due' ? '+ বাকি' : '− আদায়' }}</span>
                    <span class="text-xs text-mute ml-2">{{ $entry->note }} · {{ $entry->created_at->format('d M') }}</span>
                </div>
                <span>{{ number_format($entry->amount) }}৳ <span class="text-xs text-mute">(ব্যাল: {{ number_format($entry->balance_after) }})</span></span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">খাতা খালি।</p>
        @endforelse
    </div>
</div>
@endsection
