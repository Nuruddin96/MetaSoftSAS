@extends('layouts.super')

@section('title', $tenant->store_name)

@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $tenant->store_name }}</h1>
        <p class="text-sm text-mute">
            <a href="{{ $tenant->url() }}" target="_blank" class="text-leaf hover:underline">{{ $tenant->url() }}</a>
            · {{ $tenant->owner_name }} · {{ $tenant->owner_phone }} · {{ $tenant->owner_email }}
        </p>
    </div>
    <a href="{{ route('super.tenants.edit', $tenant) }}" class="px-4 py-2.5 rounded-lg border border-ink/15 text-sm hover:bg-white mr-3">এডিট / পাসওয়ার্ড / মুছুন</a>
    <a href="{{ route('super.tenants') }}" class="text-sm text-mute hover:text-ink">← সব টেনেন্ট</a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">স্ট্যাটাস</p><p class="font-bold text-lg mt-1">{{ $tenant->status }}</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">প্ল্যান</p><p class="font-bold text-lg mt-1">{{ $tenant->plan?->name }}</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5"><p class="text-mute text-xs">মোট অর্ডার</p><p class="font-bold text-lg mt-1">{{ $orders }}</p></div>
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">মেয়াদ শেষ</p>
        <p class="font-bold text-lg mt-1">{{ $tenant->status === 'trial' ? $tenant->trial_ends_at?->format('d M Y') : ($tenant->subscription_ends_at?->format('d M Y') ?? '—') }}</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="space-y-6">
        <div class="bg-white rounded-xl border border-ink/5 p-5">
            <p class="font-bold text-sm mb-3">⏳ সাবস্ক্রিপশন বাড়ান (ম্যানুয়াল পেমেন্ট নিলে)</p>
            <form method="POST" action="{{ route('super.tenants.extend', $tenant) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label class="block text-xs text-mute mb-1">প্ল্যান</label>
                    <select name="plan_id" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm bg-white">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected($tenant->plan_id === $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-mute mb-1">মেয়াদ শেষ হবে যেদিন</label>
                    @php
                        $currentEnd = ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
                            ? $tenant->subscription_ends_at : ($tenant->trial_ends_at ?? now());
                        $suggested = $currentEnd->isFuture() ? $currentEnd->copy()->addDays(30) : now()->addDays(30);
                    @endphp
                    <input name="subscription_ends_at" type="date" required
                           value="{{ $suggested->toDateString() }}"
                           min="{{ now()->addDay()->toDateString() }}"
                           class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                </div>
                <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সাবস্ক্রিপশন আপডেট করুন</button>
            </form>
            <p class="text-xs text-mute mt-2">
                বর্তমান মেয়াদ: {{ $tenant->status === 'trial' ? ($tenant->trial_ends_at?->format('d M Y') . ' (ট্রায়াল)') : ($tenant->subscription_ends_at?->format('d M Y') ?? '—') }}
            </p>
        </div>

        @php $domainStatus = $tenant->customDomainDisplayStatus(); @endphp
        @if ($domainStatus === 'pending')
            <div class="bg-amber/15 border border-amber/40 rounded-xl p-5">
                <p class="font-bold text-sm mb-1">🌐 কাস্টম ডোমেইন রিকোয়েস্ট — Pending</p>
                <p class="text-sm mb-3">চাচ্ছে: <b>{{ $tenant->custom_domain_requested }}</b></p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('super.tenants.domain.approve', $tenant) }}">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-leaf text-white text-sm font-semibold hover:bg-leafdk">✓ অনুমোদন করুন (Approve)</button>
                    </form>
                    <form method="POST" action="{{ route('super.tenants.domain.reject', $tenant) }}">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-red-50 text-red-600 text-sm font-semibold hover:bg-red-100">✕ প্রত্যাখ্যান করুন</button>
                    </form>
                </div>
                <p class="text-xs text-mute mt-3">অনুমোদনের পর ম্যানুয়ালি DNS/SSL সেটআপ করে Activate করুন — কোনো স্বয়ংক্রিয় DNS যাচাই প্রয়োজন নেই।</p>
            </div>
        @elseif ($domainStatus === 'approved')
            <div class="bg-leaf/10 border border-leaf/30 rounded-xl p-5">
                <p class="font-bold text-sm mb-1">✅ অনুমোদিত (Approved) — <b>{{ $tenant->custom_domain_requested }}</b></p>
                <p class="text-xs text-mute mb-3">এখন ম্যানুয়ালি ডোমেইন/DNS/SSL সেটআপ শেষ করে নিচের বাটনে ক্লিক করে Activate করুন।</p>
                @if ($domainActivationInstructions)
                    <div class="bg-white rounded-lg border border-ink/10 p-3 text-xs font-mono whitespace-pre-line mb-3">{{ $domainActivationInstructions }}</div>
                @endif
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('super.tenants.domain.activate', $tenant) }}">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-leaf text-white text-sm font-semibold hover:bg-leafdk">🚀 চালু করুন (Activate)</button>
                    </form>
                    <form method="POST" action="{{ route('super.tenants.domain.reject', $tenant) }}">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-red-50 text-red-600 text-sm font-semibold hover:bg-red-100">✕ প্রত্যাখ্যান করুন</button>
                    </form>
                </div>
            </div>
        @elseif ($domainStatus === 'active')
            <div class="bg-leaf/10 border border-leaf/30 rounded-xl p-5">
                <p class="text-sm mb-3">🌐 সক্রিয় কাস্টম ডোমেইন: <b>{{ $tenant->custom_domain }}</b></p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('super.tenants.domain.deactivate', $tenant) }}" onsubmit="return confirm('এই ডোমেইনটি নিষ্ক্রিয় করবেন? স্টোরফ্রন্ট আর এই ডোমেইনে খুলবে না, তবে ম্যাপিং মুছবে না — পরে আবার Activate করা যাবে।')">
                        @csrf
                        <button class="px-4 py-2 rounded-lg bg-amber-50 text-amber-700 text-sm font-semibold hover:bg-amber-100">⏸ নিষ্ক্রিয় করুন (Deactivate)</button>
                    </form>
                    <form method="POST" action="{{ route('super.tenants.domain.destroy', $tenant) }}" onsubmit="return confirm('এই কাস্টম ডোমেইন ম্যাপিং সম্পূর্ণ মুছে ফেলবেন? এটি আর ফিরিয়ে আনা যাবে না — টেনেন্টকে আবার নতুন করে রিকোয়েস্ট করতে হবে।')">
                        @csrf @method('DELETE')
                        <button class="px-4 py-2 rounded-lg bg-red-50 text-red-600 text-sm font-semibold hover:bg-red-100">🗑 মুছে ফেলুন</button>
                    </form>
                </div>
            </div>
        @elseif ($domainStatus === 'rejected')
            <div class="bg-red-50 border border-red-200 rounded-xl p-5 text-sm text-red-700">
                ✕ ডোমেইন রিকোয়েস্ট প্রত্যাখ্যাত: <b>{{ $tenant->custom_domain_requested }}</b>
            </div>
        @endif

        <div class="bg-white rounded-xl border border-ink/5 p-5 flex gap-3">
            @if ($tenant->status !== 'suspended')
                <form method="POST" action="{{ route('super.tenants.suspend', $tenant) }}"
                      onsubmit="return confirm('টেনেন্টের সাইট ও প্যানেল বন্ধ হয়ে যাবে। নিশ্চিত?')">
                    @csrf
                    <button class="px-5 py-2.5 rounded-lg bg-red-600 text-white font-semibold text-sm hover:bg-red-700">🚫 সাসপেন্ড করুন</button>
                </form>
            @else
                <form method="POST" action="{{ route('super.tenants.activate', $tenant) }}">
                    @csrf
                    <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">✅ আনসাসপেন্ড করুন</button>
                </form>
            @endif
            <span class="text-xs text-mute self-center">{{ $staff }} জন স্টাফ একাউন্ট</span>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">পেমেন্ট হিস্ট্রি</div>
        @forelse ($payments as $p)
            <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                <span class="text-mute">{{ $p->created_at->format('d M Y') }} · {{ $p->gateway }} ·
                    <span class="{{ $p->status === 'completed' ? 'text-leafdk' : 'text-red-600' }}">{{ $p->status }}</span></span>
                <span class="font-semibold">{{ number_format($p->amount) }}৳</span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">কোনো পেমেন্ট নেই।</p>
        @endforelse
    </div>
</div>
@endsection
