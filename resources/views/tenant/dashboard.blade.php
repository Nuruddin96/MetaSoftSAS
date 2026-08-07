@extends('layouts.panel')

@section('title', 'ড্যাশবোর্ড')

@section('content')
@if ($tenant->status === 'trial')
    <div class="mb-6 bg-amber/15 border border-amber/40 rounded-xl px-4 py-3 text-sm">
        ⏳ ট্রায়াল চলছে — শেষ হবে <b>{{ $tenant->trial_ends_at?->format('d M Y') }}</b>।
        <a href="{{ route('tenant.billing') }}" class="font-semibold text-leafdk hover:underline">এখনই প্ল্যান নিন</a>
    </div>
@endif

@php $checklistDone = collect($checklist)->filter()->count(); @endphp
@if ($checklistDone < count($checklist))
    <div class="mb-6 bg-white rounded-xl border border-ink/5 p-5 card-hover">
        <div class="flex items-center justify-between mb-3">
            <p class="font-bold text-sm">🚀 শুরু করার চেকলিস্ট</p>
            <span class="text-xs text-mute">{{ $checklistDone }}/{{ count($checklist) }} সম্পন্ন</span>
        </div>
        <div class="h-1.5 rounded-full bg-ink/5 overflow-hidden mb-4">
            <div class="h-full bg-leaf rounded-full transition-all" style="width: {{ round($checklistDone / count($checklist) * 100) }}%"></div>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
            @php
                $steps = [
                    'product' => ['প্রথম প্রোডাক্ট যোগ করুন', route('tenant.products.create')],
                    'logo'    => ['লোগো আপলোড করুন', route('tenant.website')],
                    'courier' => ['কুরিয়ার সংযোগ করুন', route('tenant.settings')],
                    'order'   => ['প্রথম অর্ডার নিন', route('tenant.orders.create')],
                ];
            @endphp
            @foreach ($steps as $key => [$label, $link])
                <a href="{{ $link }}" class="flex items-center gap-2 {{ $checklist[$key] ? 'text-mute line-through' : 'text-ink hover:text-leaf' }}">
                    <span class="w-5 h-5 rounded-full grid place-items-center shrink-0 {{ $checklist[$key] ? 'bg-leaf text-white' : 'border border-ink/20' }}">
                        {{ $checklist[$key] ? '✓' : '' }}
                    </span>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>
@endif

@php
    $todoItems = array_filter([
        $pendingOrders > 0 ? ['পেন্ডিং অর্ডার কনফার্ম করুন', $pendingOrders, route('tenant.orders.index', ['status' => 'pending']), 'clock', 'text-amber'] : null,
        $lowStockCount > 0 ? ['লো স্টক প্রোডাক্ট রিস্টক করুন', $lowStockCount, route('tenant.inventory.low'), 'triangle-alert', 'text-red-600'] : null,
        $newMessages > 0 ? ['নতুন মেসেঞ্জার মেসেজ দেখুন', $newMessages, route('tenant.messenger.index'), 'message-circle', 'text-blue-600'] : null,
        $newIncomplete > 0 ? ['অসম্পূর্ণ অর্ডারে কল করুন', $newIncomplete, route('tenant.incomplete'), 'phone-missed', 'text-mute'] : null,
    ]);
@endphp
@if (count($todoItems))
    <div class="mb-6 bg-white rounded-xl border border-ink/5 p-5 card-hover">
        <p class="font-bold text-sm mb-3">📋 আজকে যা করতে হবে</p>
        <div class="grid sm:grid-cols-2 gap-2.5">
            @foreach ($todoItems as [$label, $count, $link, $icon, $color])
                <a href="{{ $link }}" class="flex items-center justify-between gap-3 px-4 py-3 rounded-lg border border-ink/5 hover:border-leaf/30 hover:bg-paper/60 transition">
                    <span class="flex items-center gap-2.5 text-sm">
                        <i data-lucide="{{ $icon }}" class="w-4 h-4 {{ $color }}"></i>
                        {{ $label }}
                    </span>
                    <span class="w-6 h-6 rounded-full bg-ink/5 grid place-items-center text-xs font-bold {{ $color }}">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif

<div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
    @foreach ([
        ['আজকের অর্ডার', $todayOrders],
        ['আজকের বিক্রি', number_format($todaySales) . '৳'],
        ['পেন্ডিং অর্ডার', $pendingOrders],
        ['মোট প্রোডাক্ট', $totalProducts],
        ['মোট কাস্টমার', $totalCustomers],
    ] as [$label, $value])
        <div class="card-hover bg-white rounded-xl border border-ink/5 p-5">
            <p class="text-mute text-xs">{{ $label }}</p>
            <p class="font-disp font-extrabold text-2xl mt-1">{{ $value }}</p>
        </div>
    @endforeach
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">অর্ডার কোথা থেকে আসছে</div>
        @php
            $channelLabels = ['website' => 'ওয়েবসাইট', 'facebook' => 'ফেসবুক', 'whatsapp' => 'হোয়াটসঅ্যাপ', 'instagram' => 'ইনস্টাগ্রাম', 'call' => 'কল', 'others' => 'অন্যান্য'];
            $channelColors = ['website' => 'text-leaf', 'facebook' => 'text-[#1877F2]', 'whatsapp' => 'text-[#25D366]', 'instagram' => 'text-[#E1306C]', 'call' => 'text-ink', 'others' => 'text-mute'];
            $maxCh = $byChannel->max() ?: 1;
        @endphp
        @forelse ($channelLabels as $key => $label)
            <div class="px-5 py-2.5 border-b border-ink/5 last:border-0">
                <div class="flex justify-between text-sm mb-1">
                    <span class="flex items-center gap-2 {{ $channelColors[$key] }}">
                        @include('partials.icon', ['platform' => $key, 'class' => 'w-4 h-4'])
                        <span class="text-ink">{{ $label }}</span>
                    </span>
                    <span class="text-mute">{{ $byChannel[$key] ?? 0 }}</span>
                </div>
                <div class="h-1.5 rounded-full bg-ink/5 overflow-hidden">
                    <div class="h-full bg-leaf rounded-full" style="width: {{ round((($byChannel[$key] ?? 0) / $maxCh) * 100) }}%"></div>
                </div>
            </div>
        @empty
        @endforelse
    </div>

    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 flex justify-between items-center">
            <span class="font-bold text-sm">শীর্ষ জেলা</span>
            @if ($moreDistrictsCount > 0)
                <a href="{{ route('tenant.reports.locations') }}" class="text-xs text-leaf hover:underline">আরও {{ $moreDistrictsCount }}টি →</a>
            @endif
        </div>
        @forelse ($topDistricts as $d)
            <div class="flex justify-between px-5 py-2.5 border-b border-ink/5 last:border-0 text-sm">
                <span>{{ $d->name }}</span><span class="text-mute">{{ $d->orders }}টি অর্ডার</span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">এখনো কোনো অর্ডার নেই।</p>
        @endforelse
    </div>
</div>

<div class="mt-8 bg-white rounded-xl border border-ink/5">
    <div class="px-5 py-4 border-b border-ink/5 flex items-center justify-between">
        <span class="font-bold">সাম্প্রতিক অর্ডার</span>
        <a href="{{ route('tenant.orders.index') }}" class="text-sm text-leaf hover:underline">সব দেখুন →</a>
    </div>
    @if ($recentOrders->isEmpty())
        <p class="px-5 py-10 text-center text-mute text-sm">
            এখনো কোনো অর্ডার আসেনি। <a href="{{ route('tenant.products.create') }}" class="text-leaf font-semibold hover:underline">প্রথম প্রোডাক্ট যোগ করুন</a>,
            তারপর দোকানের লিংক শেয়ার করুন: <span class="font-semibold text-ink">{{ $tenant->url() }}</span>
        </p>
    @else
        <table class="w-full text-sm">
            <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                <th class="px-5 py-3">অর্ডার</th><th class="px-5 py-3">কাস্টমার</th>
                <th class="px-5 py-3">মোট</th><th class="px-5 py-3">স্ট্যাটাস</th>
            </tr></thead>
            <tbody>
            @foreach ($recentOrders as $order)
                <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                    <td class="px-5 py-3"><a class="font-medium text-leaf hover:underline" href="{{ route('tenant.orders.show', $order) }}">{{ $order->order_number }}</a></td>
                    <td class="px-5 py-3">{{ $order->customer_name }}<br><span class="text-mute text-xs">{{ $order->customer_phone }}</span></td>
                    <td class="px-5 py-3">{{ number_format($order->total) }}৳</td>
                    <td class="px-5 py-3"><span class="px-2 py-1 rounded bg-ink/5 text-xs">{{ $order->status }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="px-5 py-4 border-t border-ink/5">{{ $recentOrders->links() }}</div>
    @endif
</div>
@endsection
