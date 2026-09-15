@extends('layouts.panel')

@section('title', 'Steadfast সেন্টার')

@section('content')
<div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <h1 class="font-disp font-bold text-2xl">🚚 Steadfast সেন্টার</h1>
    <form method="GET" action="{{ route('tenant.steadfast.index') }}" class="flex items-center gap-2">
        <input type="text" name="q" value="{{ $searchTerm }}" placeholder="অর্ডার আইডি, ফোন বা কনসাইনমেন্ট নম্বর"
               class="rounded-btn border border-ink/15 px-3 py-2 text-sm bg-white w-64 max-w-full">
        <x-ui.button type="submit" variant="outline" size="sm">
            <i data-lucide="search" class="w-4 h-4"></i> খুঁজুন
        </x-ui.button>
        @if ($searchTerm)
            <a href="{{ route('tenant.steadfast.index') }}" class="text-xs text-mute hover:text-ink">মুছুন</a>
        @endif
    </form>
</div>

@unless ($steadfastActive)
    <x-ui.card tone="amber" padding="sm" class="mb-6 text-sm">
        Steadfast API সংযুক্ত নয়। <a href="{{ route('tenant.settings') }}" class="font-semibold underline">সেটিংস পেজে</a> API Key ও Secret Key দিয়ে চালু করুন।
    </x-ui.card>
@endunless

{{-- 1. BALANCE --}}
<x-ui.card class="mb-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-orange-50 text-orange-600 grid place-items-center shrink-0">
                <i data-lucide="banknote" class="w-5 h-5"></i>
            </div>
            <div>
                <p class="text-mute text-xs">বর্তমান ব্যালেন্স</p>
                @if (! $steadfastActive)
                    <p class="font-disp font-extrabold text-2xl mt-0.5">—</p>
                @elseif ($balance['error'])
                    <p class="font-disp font-extrabold text-lg mt-0.5 text-red-600">লোড করা যায়নি</p>
                @else
                    <p class="font-disp font-extrabold text-2xl mt-0.5">৳{{ number_format($balance['balance'], 2) }}</p>
                @endif
                @if ($steadfastActive && $balance['updated_at'])
                    <p class="text-xs text-mute mt-1">সর্বশেষ আপডেট: {{ $balance['updated_at']->diffForHumans() }}</p>
                @endif
            </div>
        </div>
        @if ($steadfastActive)
            <form method="POST" action="{{ route('tenant.steadfast.balance.refresh') }}">
                @csrf
                <button type="submit" class="flex items-center gap-1.5 px-4 py-2.5 rounded-btn border border-ink/15 font-semibold text-xs hover:bg-paper transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                    <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> রিফ্রেশ
                </button>
            </form>
        @endif
    </div>
</x-ui.card>

{{-- 5. COURIER OVERVIEW — bucketed from local courier_status, since
     Steadfast has no bulk "list my consignments" API to cross-check
     against; see SteadfastCenterController::index()'s comment. --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4 mb-6">
    @foreach ([
        ['মোট পার্সেল', $overview['total'], 'package', 'bg-ink/5 text-ink'],
        ['প্রসেসিং', $overview['processing'], 'truck', 'bg-blue-50 text-blue-600'],
        ['ডেলিভার্ড', $overview['delivered'], 'circle-check', 'bg-leaf/10 text-leafdk'],
        ['বাতিল', $overview['cancelled'], 'circle-x', 'bg-red-50 text-red-600'],
    ] as [$label, $value, $icon, $tone])
        <x-ui.card padding="sm">
            <div class="w-8 h-8 rounded-lg grid place-items-center {{ $tone }} mb-2">
                <i data-lucide="{{ $icon }}" class="w-4 h-4"></i>
            </div>
            <p class="font-disp font-extrabold text-xl">{{ $value }}</p>
            <p class="text-mute text-xs mt-0.5">{{ $label }}</p>
        </x-ui.card>
    @endforeach
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    {{-- 6. COD / SETTLEMENT — from our own orders table (cod_amount = order
         total for payment_method=cod orders sent to Steadfast); Steadfast's
         API has no per-order settlement-status field to enrich this with,
         only the aggregate payment-batch history shown further below. --}}
    <x-ui.card padding="none" class="overflow-hidden">
        <div class="px-5 py-3.5 border-b border-ink/5 font-bold text-sm">COD সামারি (Steadfast পার্সেল)</div>
        <div class="divide-y divide-ink/5">
            <div class="flex justify-between px-5 py-3 text-sm">
                <span class="text-mute">মোট COD</span>
                <span class="font-semibold">৳{{ number_format($cod['total'], 2) }}</span>
            </div>
            <div class="flex justify-between px-5 py-3 text-sm">
                <span class="text-mute">ডেলিভার্ড COD</span>
                <span class="font-semibold text-leafdk">৳{{ number_format($cod['delivered'], 2) }}</span>
            </div>
            <div class="flex justify-between px-5 py-3 text-sm">
                <span class="text-mute">পেন্ডিং COD</span>
                <span class="font-semibold text-amber-600">৳{{ number_format($cod['pending'], 2) }}</span>
            </div>
        </div>
    </x-ui.card>

    {{-- 2. PAYMENT REQUEST — intentionally NOT a form. Steadfast's public
         API (portal.packzy.com/api/v1) only exposes GET /payments (read-only
         history), not a POST endpoint to submit a payout/withdrawal request.
         Building a submit form here would either silently do nothing or
         have to fake success — both worse than being upfront that this
         isn't available yet. --}}
    <x-ui.card padding="sm">
        <p class="font-bold text-sm mb-2 flex items-center gap-2">
            <i data-lucide="info" class="w-4 h-4 text-mute"></i> পেমেন্ট রিকোয়েস্ট
        </p>
        <p class="text-sm text-mute leading-relaxed">
            Steadfast-এর পাবলিক API-তে পেমেন্ট/সেটেলমেন্ট রিকোয়েস্ট সাবমিট করার কোনো অফিসিয়াল endpoint নেই —
            শুধু আগের পেমেন্টের হিস্ট্রি দেখা যায় (নিচে দেখুন)। তাই এখান থেকে সরাসরি পেমেন্ট রিকোয়েস্ট পাঠানো যাচ্ছে না।
            পেমেন্ট চাইতে <a href="https://portal.packzy.com" target="_blank" rel="noopener" class="text-leaf font-semibold hover:underline">Steadfast-এর নিজস্ব প্যানেলে</a> গিয়ে অনুরোধ করুন।
        </p>
    </x-ui.card>
</div>

{{-- 7. PAYMENT REQUEST HISTORY (settlement payments Steadfast already made
     to this merchant — GET /payments). Field names below are looked up
     defensively across a few candidate keys since Steadfast's public docs
     don't pin down an exact schema for this endpoint; a dash is shown for
     anything not present rather than guessing a value. --}}
<x-ui.card padding="none" class="overflow-hidden mb-6">
    <div class="px-5 py-3.5 border-b border-ink/5 font-bold text-sm">পেমেন্ট হিস্ট্রি (Steadfast থেকে পাওয়া)</div>
    @if (! $steadfastActive)
        <div class="px-5 py-10 text-center text-mute text-sm">Steadfast সংযুক্ত নয়।</div>
    @elseif ($paymentsError)
        <div class="px-5 py-10 text-center text-red-600 text-sm">পেমেন্ট হিস্ট্রি লোড করা যায়নি: {{ $paymentsError }}</div>
    @elseif (empty($payments))
        <div class="px-5 py-10 text-center text-mute text-sm">
            <i data-lucide="receipt" class="w-6 h-6 mx-auto mb-2 text-mute/50"></i>
            এখনো কোনো পেমেন্ট হিস্ট্রি পাওয়া যায়নি।
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                    <th class="px-5 py-3 whitespace-nowrap">তারিখ</th>
                    <th class="px-5 py-3 whitespace-nowrap">পরিমাণ</th>
                    <th class="px-5 py-3 whitespace-nowrap">মাধ্যম</th>
                    <th class="px-5 py-3 whitespace-nowrap">স্ট্যাটাস</th>
                    <th class="px-5 py-3 whitespace-nowrap">রেফারেন্স</th>
                </tr></thead>
                <tbody>
                @foreach ($payments as $p)
                    @php
                        $amount = data_get($p, 'amount') ?? data_get($p, 'total_amount') ?? data_get($p, 'paid_amount');
                        $method = data_get($p, 'method') ?? data_get($p, 'payment_method') ?? data_get($p, 'type');
                        $status = data_get($p, 'status');
                        $date = data_get($p, 'created_at') ?? data_get($p, 'payment_date') ?? data_get($p, 'date');
                        $ref = data_get($p, 'reference') ?? data_get($p, 'trx_id') ?? data_get($p, 'id');
                    @endphp
                    <tr class="border-b border-ink/5 last:border-0">
                        <td class="px-5 py-3 whitespace-nowrap">{{ $date ?? '—' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap font-medium">{{ $amount !== null ? '৳'.number_format((float) $amount, 2) : '—' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $method ?? '—' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $status ?? '—' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap text-mute">{{ $ref ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>

{{-- 3. COURIER PARCELS — only orders actually sent to Steadfast
     (courier_provider = steadfast AND courier_consignment_id present),
     never the full order list. --}}
<x-ui.card padding="none" class="overflow-hidden">
    <div class="px-5 py-4 border-b border-ink/5 flex items-center justify-between">
        <span class="font-bold">Steadfast পার্সেল{{ $searchTerm ? ' — খোঁজার ফলাফল' : '' }}</span>
        <a href="{{ route('tenant.orders.index') }}" class="text-sm text-leaf hover:underline">সব অর্ডার দেখুন →</a>
    </div>
    @if ($parcels->isEmpty())
        <div class="px-5 py-12 text-center text-mute text-sm">
            <i data-lucide="truck" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
            {{ $searchTerm ? 'কোনো পার্সেল পাওয়া যায়নি।' : 'এখনো কোনো অর্ডার Steadfast-এ পাঠানো হয়নি।' }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                    <th class="px-5 py-3 whitespace-nowrap">অর্ডার</th>
                    <th class="px-5 py-3 whitespace-nowrap">কাস্টমার</th>
                    <th class="px-5 py-3 whitespace-nowrap">COD</th>
                    <th class="px-5 py-3 whitespace-nowrap">কনসাইনমেন্ট</th>
                    <th class="px-5 py-3 whitespace-nowrap">স্ট্যাটাস</th>
                </tr></thead>
                <tbody>
                @foreach ($parcels as $order)
                    @php $bucket = \App\Services\Courier\SteadfastService::statusBucket($order->courier_status); @endphp
                    <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                        <td class="px-5 py-3 whitespace-nowrap"><a class="font-medium text-leaf hover:underline" href="{{ route('tenant.orders.show', $order) }}">{{ $order->order_number }}</a></td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $order->customer_name }}<br><span class="text-mute text-xs">{{ $order->customer_phone }}</span></td>
                        <td class="px-5 py-3 whitespace-nowrap">{{ $order->payment_method === 'cod' ? '৳'.number_format($order->total - $order->paid_amount, 2) : '—' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap text-xs text-mute">{{ $order->courier_consignment_id }}</td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            <span class="px-2.5 py-1 rounded-pill text-xs font-semibold {{ match($bucket) { 'delivered' => 'bg-leaf/10 text-leafdk', 'cancelled' => 'bg-red-50 text-red-700', default => 'bg-blue-50 text-blue-700' } }}">
                                {{ $order->courier_status ?? 'pending' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-ink/5">{{ $parcels->links() }}</div>
    @endif
</x-ui.card>
@endsection
