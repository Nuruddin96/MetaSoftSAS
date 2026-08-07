@extends('layouts.super')
@section('title', $client->business_name)
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $client->business_name }}</h1>
        <p class="text-sm text-mute">{{ $client->client_name }} · {{ $client->phone }} @if($client->address) · {{ $client->address }} @endif</p>
        @if ($client->service)<p class="text-sm text-mute mt-1">সার্ভিস: {{ $client->service }}</p>@endif
    </div>
    <div class="flex items-center gap-3">
        <a href="{{ route('super.clients.edit', $client) }}" class="px-4 py-2.5 rounded-lg border border-ink/15 text-sm hover:bg-white">এডিট</a>
        <a href="{{ route('super.clients.index') }}" class="text-sm text-mute hover:text-ink">← সব ক্লায়েন্ট</a>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মাসিক চার্জ</p><p class="font-disp font-extrabold text-xl mt-1">{{ $client->monthly_charge ? number_format($client->monthly_charge).'৳' : '—' }}</p></div>
    <div class="bg-white rounded-xl border {{ $client->due_amount > 0 ? 'border-red-200 bg-red-50/50' : 'border-ink/5' }} p-5"><p class="text-mute text-xs">ডিউ</p><p class="font-disp font-extrabold text-xl mt-1 {{ $client->due_amount > 0 ? 'text-red-600' : '' }}">{{ number_format($client->due_amount) }}৳</p></div>
    <div class="bg-white rounded-xl border {{ $client->advance_amount > 0 ? 'border-leaf/30 bg-leaf/5' : 'border-ink/5' }} p-5"><p class="text-mute text-xs">অগ্রিম</p><p class="font-disp font-extrabold text-xl mt-1 {{ $client->advance_amount > 0 ? 'text-leafdk' : '' }}">{{ number_format($client->advance_amount) }}৳</p></div>
</div>

@if ($client->note)
    <div class="bg-amber/10 border border-amber/30 rounded-xl p-4 text-sm mb-6">📝 {{ $client->note }}</div>
@endif

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl border border-ink/5 p-6 sticky top-4">
            <p class="font-bold text-sm mb-4">💳 নতুন পেমেন্ট এন্ট্রি</p>
            <form method="POST" action="{{ route('super.clients.payments.store', $client) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs text-mute">পেমেন্টের ধরন</label>
                    <select name="payment_type" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white">
                        <option value="monthly">মাসিক বিল</option>
                        <option value="ad_spend">অ্যাড খরচ (ডলার)</option>
                        <option value="advance">অগ্রিম পেমেন্ট</option>
                        <option value="other">অন্যান্য</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-mute">পরিমাণ</label>
                        <input name="amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-mute">মুদ্রা</label>
                        <select name="currency" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white">
                            <option value="BDT">৳ BDT</option>
                            <option value="USD">$ USD</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-xs text-mute">পেমেন্ট গেটওয়ে</label>
                    <select name="gateway" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white">
                        <option value="bkash">বিকাশ</option>
                        <option value="nagad">নগদ</option>
                        <option value="bank">ব্যাংক</option>
                        <option value="cash">ক্যাশ</option>
                        <option value="other">অন্যান্য</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-mute">কোন মাসের জন্য (ঐচ্ছিক)</label>
                    <input name="month_for" placeholder="যেমন: জুলাই ২০২৬" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs text-mute">পেমেন্টের তারিখ</label>
                    <input name="payment_date" type="date" required value="{{ now()->toDateString() }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs text-mute">রিসিট/স্ক্রিনশট (ঐচ্ছিক)</label>
                    <input type="file" name="attachment" accept="image/*,.pdf" class="mt-1 w-full text-xs">
                </div>
                <div>
                    <label class="text-xs text-mute">নোট</label>
                    <input name="note" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
                <button class="w-full py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">এন্ট্রি যোগ করুন</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border border-ink/5">
            <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">পেমেন্ট হিস্ট্রি</div>
            @forelse ($payments as $p)
                <div class="flex items-center justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                    <div>
                        <p class="font-medium">
                            {{ ['monthly' => 'মাসিক বিল', 'ad_spend' => 'অ্যাড খরচ', 'advance' => 'অগ্রিম', 'other' => 'অন্যান্য'][$p->payment_type] }}
                            @if ($p->month_for) <span class="text-xs text-mute">({{ $p->month_for }})</span> @endif
                        </p>
                        <p class="text-xs text-mute">{{ $p->payment_date->format('d M Y') }} · {{ strtoupper($p->gateway) }}
                            @if ($p->note) · {{ $p->note }} @endif</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-semibold">{{ $p->currency === 'USD' ? '$' : '' }}{{ number_format($p->amount) }}{{ $p->currency === 'BDT' ? '৳' : '' }}</span>
                        @if ($p->attachment_path)
                            <a href="{{ asset('storage/'.$p->attachment_path) }}" target="_blank" class="text-leaf text-xs hover:underline">📎 দেখুন</a>
                        @endif
                        <form method="POST" action="{{ route('super.clients.payments.destroy', $p) }}" onsubmit="return confirm('মুছবেন?')">
                            @csrf @method('DELETE')
                            <button class="text-red-600 text-xs hover:underline">মুছুন</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="px-5 py-10 text-center text-mute text-sm">কোনো পেমেন্ট এন্ট্রি নেই।</p>
            @endforelse
        </div>
        <div class="mt-4">{{ $payments->links() }}</div>
    </div>
</div>
@endsection
