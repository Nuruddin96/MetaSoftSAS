@extends('layouts.super')
@section('title', $affiliate->name)
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $affiliate->name }}</h1>
        <p class="text-sm text-mute">{{ $affiliate->email }} · {{ $affiliate->phone }} · কোড: <b>{{ $affiliate->referral_code }}</b></p>
    </div>
    <div class="flex items-center gap-3">
        <a href="{{ route('super.affiliates.edit', $affiliate) }}" class="px-4 py-2.5 rounded-lg border border-ink/15 text-sm hover:bg-white">এডিট</a>
        <form method="POST" action="{{ route('super.affiliates.suspend', $affiliate) }}">
            @csrf
            <button class="px-4 py-2.5 rounded-lg {{ $affiliate->status === 'active' ? 'bg-red-600 text-white' : 'bg-leaf text-white' }} font-semibold text-sm">
                {{ $affiliate->status === 'active' ? '🚫 সাসপেন্ড' : '✅ আনসাসপেন্ড' }}
            </button>
        </form>
        <form method="POST" action="{{ route('super.affiliates.destroy', $affiliate) }}" onsubmit="return confirm('এই অ্যাফিলিয়েট মুছে ফেলবেন? এর সাথে সব কমিশন হিস্ট্রিও মুছে যাবে।')">
            @csrf @method('DELETE')
            <button class="px-4 py-2.5 rounded-lg bg-red-50 text-red-600 font-semibold text-sm hover:bg-red-100">মুছুন</button>
        </form>
        <a href="{{ route('super.affiliates') }}" class="text-sm text-mute hover:text-ink">← সব অ্যাফিলিয়েট</a>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">রেফার করা দোকান ({{ $tenants->count() }})</div>
        @forelse ($tenants as $t)
            <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                <span>{{ $t->store_name }}</span><span class="text-xs text-mute">{{ $t->status }}</span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">কোনো রেফার নেই।</p>
        @endforelse
    </div>

    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">কমিশন</div>
        @forelse ($commissions as $c)
            <div class="flex items-center justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                <div>
                    <p>{{ $c->source_label }}</p>
                    <p class="text-xs text-mute">{{ $c->type === 'saas' ? 'SaaS রেফারেল' : 'সার্ভিস রেফারেল' }} · {{ $c->created_at->format('d M Y') }}</p>
                </div>
                <div class="text-right">
                    <p class="font-semibold">{{ number_format($c->amount) }}৳</p>
                    @if ($c->status === 'pending')
                        <form method="POST" action="{{ route('super.affiliates.commission.paid', $c) }}">
                            @csrf
                            <button class="text-xs text-leaf hover:underline">পরিশোধিত চিহ্নিত করুন</button>
                        </form>
                    @else
                        <span class="text-xs text-leafdk">পরিশোধিত ✓</span>
                    @endif
                </div>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">কোনো কমিশন নেই।</p>
        @endforelse
    </div>
</div>

<div class="bg-white rounded-xl border border-ink/5 mt-6">
    <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">সার্ভিস লিড ({{ $leads->count() }})</div>
    @forelse ($leads as $l)
        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
            <div>
                <p class="font-medium">{{ $l->client_name }} <span class="text-xs text-mute">{{ $l->client_phone }}</span></p>
                <p class="text-xs text-mute">{{ $l->package }} @if($l->note) · {{ $l->note }} @endif</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('super.affiliates.lead.update', $l) }}" class="flex items-center gap-2">
                    @csrf @method('PUT')
                    <select name="status" onchange="this.form.submit()" class="rounded border border-ink/15 px-2 py-1 text-xs bg-white">
                        @foreach (['new' => 'নতুন', 'contacted' => 'যোগাযোগ হয়েছে', 'converted' => 'কনভার্টেড', 'lost' => 'হারানো'] as $k => $v)
                            <option value="{{ $k }}" @selected($l->status === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </form>
                @if ($l->status === 'converted')
                    <form method="POST" action="{{ route('super.affiliates.lead.commission', $l) }}">
                        @csrf
                        <button class="px-3 py-1 rounded bg-amber text-ink text-xs font-semibold">+১০০০৳ এই মাসের কমিশন</button>
                    </form>
                @endif
            </div>
        </div>
    @empty
        <p class="px-5 py-8 text-center text-mute text-sm">কোনো লিড নেই।</p>
    @endforelse
</div>
@endsection
