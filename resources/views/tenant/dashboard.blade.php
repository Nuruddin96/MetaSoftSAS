@extends('layouts.panel')

@section('title', 'ড্যাশবোর্ড')

@section('content')
@if ($tenant->status === 'trial')
    <x-ui.card padding="sm" tone="amber" class="mb-6">
        <div class="flex items-center gap-2 text-sm">
            <i data-lucide="clock" class="w-4 h-4 shrink-0"></i>
            <span>ট্রায়াল চলছে — শেষ হবে <b>{{ $tenant->trial_ends_at?->format('d M Y') }}</b>।</span>
            <a href="{{ route('tenant.billing') }}" class="font-semibold text-leafdk hover:underline shrink-0">এখনই প্ল্যান নিন</a>
        </div>
    </x-ui.card>
@endif

@php $checklistDone = collect($checklist)->filter()->count(); @endphp
@if ($checklistDone < count($checklist))
    <x-ui.card class="mb-6">
        <div class="flex items-center justify-between mb-3">
            <p class="font-bold text-sm flex items-center gap-2">
                <i data-lucide="rocket" class="w-4 h-4 text-leafdk"></i> শুরু করার চেকলিস্ট
            </p>
            <span class="text-xs text-mute">{{ $checklistDone }}/{{ count($checklist) }} সম্পন্ন</span>
        </div>
        <div class="h-1.5 rounded-pill bg-ink/5 overflow-hidden mb-4">
            <div class="h-full bg-leaf rounded-pill transition-all" style="width: {{ round($checklistDone / count($checklist) * 100) }}%"></div>
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
                <a href="{{ $link }}" class="flex items-center gap-2 rounded transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 {{ $checklist[$key] ? 'text-mute line-through' : 'text-ink hover:text-leaf' }}">
                    <span class="w-5 h-5 rounded-pill grid place-items-center shrink-0 {{ $checklist[$key] ? 'bg-leaf text-white' : 'border border-ink/20' }}">
                        @if ($checklist[$key])
                            <i data-lucide="check" class="w-3 h-3"></i>
                        @endif
                    </span>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </x-ui.card>
@endif

@php
    $todoItems = array_filter([
        $pendingOrders > 0 ? ['পেন্ডিং অর্ডার কনফার্ম করুন', $pendingOrders, route('tenant.orders.index', ['status' => 'pending']), 'clock', 'text-amber', 'bg-amber/10'] : null,
        $lowStockCount > 0 ? ['লো স্টক প্রোডাক্ট রিস্টক করুন', $lowStockCount, route('tenant.inventory.low'), 'triangle-alert', 'text-red-600', 'bg-red-50'] : null,
        $newMessages > 0 ? ['নতুন মেসেঞ্জার মেসেজ দেখুন', $newMessages, route('tenant.messenger.index'), 'message-circle', 'text-blue-600', 'bg-blue-50'] : null,
        $newIncomplete > 0 ? ['অসম্পূর্ণ অর্ডারে কল করুন', $newIncomplete, route('tenant.incomplete'), 'phone-missed', 'text-mute', 'bg-ink/5'] : null,
    ]);
@endphp
{{-- This is this app's actual existing "quick action" surface — a
     dynamic shortlist of things to do now, each already an existing
     route/label/count. No section literally named "Quick Actions" exists
     elsewhere in the app, so the reference's centered-tile treatment is
     applied here rather than inventing a new section. Every label/count/
     route below is untouched; only the tile's visual structure changes,
     and only below the lg: breakpoint — the inner icon+label wrapper and
     trailing count pill reproduce the pre-redesign desktop markup exactly
     (same classes, same nesting), just gated behind lg: instead of being
     unconditional, so desktop is pixel-for-pixel unchanged. The mobile
     view keeps the same count information as a small corner badge on the
     icon instead of a trailing pill, since content is now centered. --}}
@if (count($todoItems))
    <x-ui.card class="mb-6">
        <p class="font-bold text-sm mb-3 flex items-center gap-2">
            <i data-lucide="clipboard-list" class="w-4 h-4 text-leafdk"></i> আজকে যা করতে হবে
        </p>
        <div class="grid grid-cols-2 gap-2.5">
            @foreach ($todoItems as [$label, $count, $link, $icon, $textColor, $bgColor])
                <a href="{{ $link }}"
                   class="flex flex-col items-center text-center gap-1.5 px-3 py-4 rounded-[11px] border border-ink/5 hover:border-leaf/30 hover:bg-paper/60 active:scale-[0.97] transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2
                          lg:flex-row lg:items-center lg:justify-between lg:gap-3 lg:text-left lg:px-4 lg:py-3 lg:rounded-btn">
                    <span class="flex flex-col items-center gap-1.5 lg:flex-row lg:items-center lg:gap-2.5 lg:text-sm">
                        <span class="relative w-9 h-9 rounded-[9px] {{ $bgColor }} grid place-items-center lg:w-auto lg:h-auto lg:rounded-none lg:bg-transparent">
                            <i data-lucide="{{ $icon }}" class="w-5 h-5 {{ $textColor }} lg:w-4 lg:h-4"></i>
                            <span class="lg:hidden absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 rounded-pill {{ $bgColor }} {{ $textColor }} text-[10px] font-bold grid place-items-center border-2 border-white">{{ $count }}</span>
                        </span>
                        <span class="text-[15px] font-medium leading-tight lg:text-sm lg:font-normal">{{ $label }}</span>
                    </span>
                    <span class="hidden lg:grid w-6 h-6 rounded-pill {{ $bgColor }} place-items-center text-xs font-bold {{ $textColor }}">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    </x-ui.card>
@endif

{{-- KPI stat tiles — icon + label + value, revenue tile gets a leaf accent to
     stand out from count-based metrics. No trend arrows: the controller
     doesn't compute a vs-yesterday comparison, so nothing is shown rather
     than fabricating one.

     Mobile sizing/coloring (base classes) is intentionally denser and uses
     a per-category pastel icon tone, app-tile-like; every lg: class below
     reproduces the pre-redesign desktop values exactly (p-6 / w-9 h-9 /
     rounded-lg / 18px icon / text-3xl / bg-paper text-mute or the existing
     leaf accent on the revenue tile), so desktop is pixel-for-pixel
     unchanged — only the mobile breakpoint gets the new look.

     Sizing follows the reference-UI analysis spec precisely: 32px icon box
     (8-10px radius), 18-20px icon, 11px card radius, 12-14px padding,
     20-22px bold number, 15px label — icon-then-NUMBER-then-label order
     (a flex-column with per-item `order-*`, not a DOM reorder, so desktop
     can keep its original label-then-number visual order via lg:order-*
     without any duplicated markup). Every lg: class below reproduces the
     pre-redesign desktop values exactly (p-6 / w-9 h-9 / rounded-lg /
     rounded-card / 18px icon / text-3xl / label-before-number / bg-paper
     text-mute or the existing leaf accent on the revenue tile) — desktop
     is pixel-for-pixel unchanged. Each tile links to the existing list
     page it summarizes, using the exact same routes/params already used
     elsewhere on this page (e.g. the pending-orders link matches the
     "today's to-do" list below verbatim) — no new routes. --}}
<div class="grid grid-cols-2 lg:grid-cols-6 gap-2.5 lg:gap-4">
    @php
        $stats = [
            ['আজকের অর্ডার', $todayOrders, 'receipt', false, 'bg-amber-50 text-amber-600 lg:bg-paper lg:text-mute', route('tenant.orders.index')],
            ['আজকের বিক্রি', number_format($todaySales) . '৳', 'trending-up', true, 'bg-leaf/10 text-leafdk', route('tenant.reports.sales')],
            ['পেন্ডিং অর্ডার', $pendingOrders, 'clock', false, 'bg-blue-50 text-blue-600 lg:bg-paper lg:text-mute', route('tenant.orders.index', ['status' => 'pending'])],
            // Was a plain Product::count() ("মোট প্রোডাক্ট") — repurposed to
            // count orders currently with a courier and not yet resolved
            // (delivered/cancelled/returned), since that's the actionable
            // number for this spot, not the static catalog size.
            ['কুরিয়ারে পেন্ডিং', $courierPendingCount, 'truck', false, 'bg-purple-50 text-purple-600 lg:bg-paper lg:text-mute', route('tenant.orders.index', ['courier' => 'pending'])],
            ['মোট কাস্টমার', $totalCustomers, 'users', false, 'bg-pink-50 text-pink-600 lg:bg-paper lg:text-mute', route('tenant.customers.index')],
            ['খরচ', number_format($todayExpenses) . '৳', 'wallet', false, 'bg-red-50 text-red-600 lg:bg-paper lg:text-mute', route('tenant.expenses.index')],
        ];
    @endphp
    @foreach ($stats as [$label, $value, $icon, $isRevenue, $iconTone, $link])
        <a href="{{ $link }}" class="block rounded-[11px] lg:rounded-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            <x-ui.card hoverable padding="none" radius="none" class="flex flex-col rounded-[11px] lg:rounded-card p-3.5 lg:p-6 active:scale-[0.97] h-full">
                <div class="order-1 w-8 h-8 lg:w-9 lg:h-9 rounded-[9px] lg:rounded-lg grid place-items-center {{ $iconTone }}">
                    <i data-lucide="{{ $icon }}" class="w-5 h-5 lg:w-[18px] lg:h-[18px]"></i>
                </div>
                <p class="order-2 lg:order-3 font-disp font-extrabold text-xl lg:text-3xl mt-2 lg:mt-1 {{ $isRevenue ? 'text-leafdk' : '' }}">{{ $value }}</p>
                <p class="order-3 lg:order-2 text-mute text-[15px] lg:text-xs font-medium lg:font-normal mt-0.5 lg:mt-3 leading-tight">{{ $label }}</p>
            </x-ui.card>
        </a>
    @endforeach
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="px-5 py-3.5 border-b border-ink/5 font-bold text-sm">অর্ডার কোথা থেকে আসছে</div>
        @php
            $channelLabels = ['website' => 'ওয়েবসাইট', 'facebook' => 'ফেসবুক', 'whatsapp' => 'হোয়াটসঅ্যাপ', 'instagram' => 'ইনস্টাগ্রাম', 'call' => 'কল', 'others' => 'অন্যান্য'];
            $channelColors = ['website' => 'text-leaf', 'facebook' => 'text-[#1877F2]', 'whatsapp' => 'text-[#25D366]', 'instagram' => 'text-[#E1306C]', 'call' => 'text-ink', 'others' => 'text-mute'];
            $maxCh = $byChannel->max() ?: 1;
        @endphp
        @foreach ($channelLabels as $key => $label)
            <div class="px-5 py-2.5 border-b border-ink/5 last:border-0">
                <div class="flex justify-between text-sm mb-1">
                    <span class="flex items-center gap-2 {{ $channelColors[$key] }}">
                        @include('partials.icon', ['platform' => $key, 'class' => 'w-4 h-4'])
                        <span class="text-ink">{{ $label }}</span>
                    </span>
                    <span class="text-mute">{{ $byChannel[$key] ?? 0 }}</span>
                </div>
                <div class="h-1.5 rounded-pill bg-ink/5 overflow-hidden">
                    <div class="h-full bg-leaf rounded-pill" style="width: {{ round((($byChannel[$key] ?? 0) / $maxCh) * 100) }}%"></div>
                </div>
            </div>
        @endforeach
    </x-ui.card>

    <x-ui.card padding="none" class="overflow-hidden">
        <div class="px-5 py-3.5 border-b border-ink/5 flex justify-between items-center">
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
            <div class="px-5 py-10 text-center text-mute text-sm">
                <i data-lucide="map-pin" class="w-6 h-6 mx-auto mb-2 text-mute/50"></i>
                এখনো কোনো অর্ডার নেই।
            </div>
        @endforelse
    </x-ui.card>
</div>

<x-ui.card padding="none" class="overflow-hidden mt-6">
    <div class="px-5 py-4 border-b border-ink/5 flex items-center justify-between">
        <span class="font-bold">সাম্প্রতিক অর্ডার</span>
        <a href="{{ route('tenant.orders.index') }}" class="text-sm text-leaf hover:underline">সব দেখুন →</a>
    </div>
    @if ($recentOrders->isEmpty())
        <div class="px-5 py-12 text-center text-mute text-sm">
            <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
            এখনো কোনো অর্ডার আসেনি। <a href="{{ route('tenant.products.create') }}" class="text-leaf font-semibold hover:underline">প্রথম প্রোডাক্ট যোগ করুন</a>,
            তারপর দোকানের লিংক শেয়ার করুন: <span class="font-semibold text-ink">{{ $tenant->url() }}</span>
        </div>
    @else
        @php
            $statusMeta = [
                'pending'    => ['অপেক্ষমান', 'bg-amber/15 text-ink'],
                'confirmed'  => ['কনফার্মড', 'bg-leaf/10 text-leafdk'],
                'processing' => ['প্রসেসিং', 'bg-blue-50 text-blue-700'],
                'shipped'    => ['শিপড', 'bg-blue-50 text-blue-700'],
                'delivered'  => ['ডেলিভার্ড', 'bg-leaf/10 text-leafdk'],
                'cancelled'  => ['বাতিল', 'bg-red-50 text-red-700'],
                'returned'   => ['ফেরত', 'bg-red-50 text-red-700'],
            ];
        @endphp
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                    <th class="px-5 py-3 whitespace-nowrap">অর্ডার</th><th class="px-5 py-3 whitespace-nowrap">কাস্টমার</th>
                    <th class="px-5 py-3 whitespace-nowrap">মোট</th><th class="px-5 py-3 whitespace-nowrap">স্ট্যাটাস</th>
                </tr></thead>
                <tbody>
                @foreach ($recentOrders as $order)
                    @php [$statusLabel, $statusClass] = $statusMeta[$order->status] ?? [$order->status, 'bg-ink/5 text-ink']; @endphp
                    <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                        <td class="px-5 py-3 whitespace-nowrap"><a class="font-medium text-leaf hover:underline" href="{{ route('tenant.orders.show', $order) }}">{{ $order->order_number }}</a></td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $order->customer_name }}<br><span class="text-mute text-xs">{{ $order->customer_phone }}</span></td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ number_format($order->total) }}৳</td>
                        <td class="px-5 py-3 whitespace-nowrap"><span class="px-2.5 py-1 rounded-pill text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-ink/5">{{ $recentOrders->links() }}</div>
    @endif
</x-ui.card>
@endsection
