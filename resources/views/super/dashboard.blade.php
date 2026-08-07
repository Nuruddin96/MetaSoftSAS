@extends('layouts.super')

@section('title', 'ড্যাশবোর্ড')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">প্ল্যাটফর্ম ওভারভিউ</h1>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    @foreach ([
        ['মোট টেনেন্ট', $totalTenants, ''],
        ['অ্যাক্টিভ', $activeTenants, 'text-leafdk'],
        ['ট্রায়ালে', $trialTenants, 'text-amber'],
        ['এক্সপায়ার্ড/সাসপেন্ড', $expiredTenants, 'text-red-600'],
    ] as [$label, $value, $cls])
        <div class="bg-white rounded-xl border border-ink/5 p-5">
            <p class="text-mute text-xs">{{ $label }}</p>
            <p class="font-disp font-extrabold text-3xl mt-1 {{ $cls }}">{{ $value }}</p>
        </div>
    @endforeach
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mt-4">
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">এ মাসের আয়</p>
        <p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($monthRevenue) }}৳</p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">মোট আয়</p>
        <p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($totalRevenue) }}৳</p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-5">
        <p class="text-mute text-xs">প্ল্যাটফর্মে মোট অর্ডার</p>
        <p class="font-disp font-extrabold text-2xl mt-1">{{ number_format($totalOrders) }}</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-8">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 flex justify-between items-center">
            <span class="font-bold text-sm">নতুন টেনেন্ট</span>
            <a href="{{ route('super.tenants') }}" class="text-xs text-leaf hover:underline">সব →</a>
        </div>
        @foreach ($recentTenants as $t)
            <a href="{{ route('super.tenants.show', $t) }}" class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 hover:bg-paper/60 text-sm">
                <span>
                    {{ $t->store_name }} <span class="text-xs text-mute">({{ $t->subdomain }})</span><br>
                    <span class="text-xs text-mute">👤 {{ $t->owner_name }}</span>
                </span>
                <span class="px-2 py-0.5 rounded text-xs h-fit {{ ['active' => 'bg-leaf/10 text-leafdk', 'trial' => 'bg-amber/15', 'expired' => 'bg-red-50 text-red-600', 'suspended' => 'bg-red-50 text-red-600'][$t->status] ?? 'bg-ink/5' }}">{{ $t->status }}</span>
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">সাম্প্রতিক পেমেন্ট</div>
        @forelse ($recentPayments as $p)
            <div class="flex justify-between px-5 py-3 border-b border-ink/5 last:border-0 text-sm">
                <span class="text-mute">{{ $p->trx_id }} · {{ $p->gateway }}</span>
                <span class="font-semibold">{{ number_format($p->amount) }}৳</span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">এখনো কোনো পেমেন্ট নেই।</p>
        @endforelse
    </div>
</div>
@endsection
