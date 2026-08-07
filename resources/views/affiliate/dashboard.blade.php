@extends('layouts.central')
@section('title', 'অ্যাফিলিয়েট ড্যাশবোর্ড')
@section('content')
<div class="min-h-screen">
    <header class="bg-ink text-white">
        <div class="max-w-4xl mx-auto px-4 h-14 flex items-center justify-between">
            <p class="font-disp font-bold">💰 অ্যাফিলিয়েট প্যানেল</p>
            <form method="POST" action="{{ route('affiliate.logout') }}">@csrf<button class="text-white/70 hover:text-white text-sm">লগআউট</button></form>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-8">
        @if (session('success'))<div class="mb-6 bg-leaf/10 border border-leaf/30 text-leafdk rounded-xl p-4 text-sm">{{ session('success') }}</div>@endif

        <div class="bg-white rounded-2xl border border-ink/5 p-6 mb-6">
            <p class="font-bold text-sm mb-2">আপনার রেফারেল লিংক</p>
            <div class="flex gap-2">
                <input id="refLink" readonly value="https://metasoftbd.com/register?ref={{ $affiliate->referral_code }}"
                       class="flex-1 rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-paper">
                <button onclick="navigator.clipboard.writeText(document.getElementById('refLink').value); this.textContent='কপি হয়েছে ✓'"
                        class="px-4 py-2.5 rounded-lg bg-ink text-white text-sm font-semibold">কপি করুন</button>
            </div>
            <p class="text-xs text-mute mt-2">কোড: <b>{{ $affiliate->referral_code }}</b> — এই লিংক শেয়ার করুন, কেউ সাইনআপ করলে ট্র্যাক হবে</p>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">অপেক্ষমান কমিশন</p><p class="font-disp font-extrabold text-2xl mt-1 text-amber">{{ number_format($pendingTotal) }}৳</p></div>
            <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট পরিশোধিত</p><p class="font-disp font-extrabold text-2xl mt-1 text-leafdk">{{ number_format($paidTotal) }}৳</p></div>
        </div>

        <div class="bg-white rounded-2xl border border-ink/5 p-6 mb-6">
            <p class="font-bold text-sm mb-3">🔵 সার্ভিস প্যাকেজের ক্লায়েন্ট রেফার করুন (১০০০৳/মাস লাইফটাইম)</p>
            <form method="POST" action="{{ route('affiliate.lead.submit') }}" class="grid md:grid-cols-2 gap-3">
                @csrf
                <input name="client_name" required placeholder="ক্লায়েন্টের নাম" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="client_phone" required placeholder="ক্লায়েন্টের নাম্বার" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <select name="package" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
                    <option value="">প্যাকেজ বাছাই করুন</option>
                    <option>Package 1 — WooCommerce Website</option>
                    <option>Package 2 — Content Creation</option>
                    <option>Package 3 — Digital Marketing</option>
                    <option>Package 4 — Business Growth Partner</option>
                </select>
                <input name="note" placeholder="নোট (ঐচ্ছিক)" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <button class="md:col-span-2 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">পাঠান</button>
            </form>
        </div>

        <div class="grid lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl border border-ink/5">
                <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">রেফার করা দোকান</div>
                @forelse ($referredTenants as $t)
                    <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                        <span>{{ $t->store_name }}</span><span class="text-xs text-mute">{{ $t->status }}</span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-mute text-sm">এখনো কেউ সাইনআপ করেনি।</p>
                @endforelse
            </div>
            <div class="bg-white rounded-xl border border-ink/5">
                <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">কমিশন হিস্ট্রি</div>
                @forelse ($commissions as $c)
                    <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                        <span class="text-mute">{{ $c->source_label }}</span>
                        <span>{{ number_format($c->amount) }}৳ <span class="text-xs {{ $c->status === 'paid' ? 'text-leafdk' : 'text-amber' }}">({{ $c->status }})</span></span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-mute text-sm">এখনো কোনো কমিশন নেই।</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-xl border border-ink/5 mt-6">
            <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">আপনার পাঠানো লিড</div>
            @forelse ($serviceLeads as $l)
                <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                    <span>{{ $l->client_name }} — {{ $l->package }}</span><span class="text-xs text-mute">{{ $l->status }}</span>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-mute text-sm">কোনো লিড পাঠাননি।</p>
            @endforelse
        </div>
    </main>
</div>
@endsection
