@extends('layouts.panel')

@section('title', 'অর্ডার')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">অর্ডার</h1>
    <x-ui.button href="{{ route('tenant.orders.create') }}" variant="accent" size="sm">+ নতুন অর্ডার</x-ui.button>
</div>

<form class="flex flex-col sm:flex-row sm:flex-wrap gap-3 mb-4">
    <input name="q" value="{{ request('q') }}" placeholder="অর্ডার নং / ফোন / নাম..."
           class="rounded-btn border border-ink/15 px-3 py-3 sm:py-2.5 text-sm w-full sm:w-auto md:w-64 focus:ring-2 focus:ring-leaf outline-none">
    <select name="status" onchange="this.form.submit()" class="rounded-btn border border-ink/15 px-3 py-3 sm:py-2.5 text-sm bg-white w-full sm:w-auto">
        <option value="">সব স্ট্যাটাস</option>
        @foreach (['pending' => 'পেন্ডিং', 'confirmed' => 'কনফার্মড', 'processing' => 'প্রসেসিং', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'ক্যান্সেলড', 'returned' => 'রিটার্নড'] as $key => $label)
            <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="channel" onchange="this.form.submit()" class="rounded-btn border border-ink/15 px-3 py-3 sm:py-2.5 text-sm bg-white w-full sm:w-auto">
        <option value="">সব উৎস</option>
        @foreach (['website' => '🌐 ওয়েবসাইট', 'facebook' => '📘 ফেসবুক', 'whatsapp' => '💬 হোয়াটসঅ্যাপ', 'instagram' => '📷 ইনস্টাগ্রাম', 'call' => '📞 কল', 'wordpress' => '🔌 ওয়ার্ডপ্রেস', 'others' => '📦 অন্যান্য'] as $key => $label)
            <option value="{{ $key }}" @selected(request('channel') === $key)>{{ $label }}</option>
        @endforeach
    </select>
    @if (request('q') || request('status') || request('channel'))
        <a href="{{ route('tenant.orders.index') }}" class="text-sm text-mute hover:text-ink self-start sm:self-center underline sm:no-underline">রিসেট</a>
    @endif
</form>

{{-- bulk action bar --}}
<div id="bulkBar" class="hidden mb-4 bg-ink text-white rounded-card px-4 py-3 flex flex-wrap items-center gap-3 text-sm">
    <span><span id="selCount">0</span>টি সিলেক্ট করা হয়েছে</span>

    <form method="POST" action="{{ route('tenant.orders.bulk-status') }}" id="bulkStatusForm" class="flex items-center gap-2">
        @csrf
        <select name="status" class="rounded-btn text-ink px-2 py-1.5 text-sm">
            @foreach (['pending' => 'পেন্ডিং', 'confirmed' => 'কনফার্মড', 'processing' => 'প্রসেসিং', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'ক্যান্সেলড'] as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <button class="px-3 py-1.5 rounded-btn bg-white/15 hover:bg-white/25 transition">স্ট্যাটাস বদলান</button>
    </form>

    <form method="POST" action="{{ route('tenant.orders.bulk-courier') }}" id="bulkCourierForm" class="flex items-center gap-2"
          onsubmit="return confirm('সিলেক্ট করা অর্ডারগুলো কুরিয়ারে পাঠাবেন?')">
        @csrf
        <select name="provider" class="rounded-btn text-ink px-2 py-1.5 text-sm">
            <option value="steadfast">Steadfast</option>
            <option value="pathao">Pathao</option>
        </select>
        <button class="px-3 py-1.5 rounded-btn bg-amber text-ink font-semibold hover:opacity-90 transition">🚚 কুরিয়ারে পাঠান</button>
    </form>
</div>

@php
    $statusMeta = [
        'pending'    => ['পেন্ডিং', 'bg-amber/15 text-ink'],
        'confirmed'  => ['কনফার্মড', 'bg-leaf/10 text-leafdk'],
        'processing' => ['প্রসেসিং', 'bg-blue-50 text-blue-700'],
        'shipped'    => ['শিপড', 'bg-blue-50 text-blue-700'],
        'delivered'  => ['ডেলিভার্ড', 'bg-leaf/10 text-leafdk'],
        'cancelled'  => ['বাতিল', 'bg-red-50 text-red-700'],
        'returned'   => ['ফেরত', 'bg-red-50 text-red-700'],
    ];
    $channelLabels = ['website' => 'ওয়েবসাইট', 'facebook' => 'ফেসবুক', 'whatsapp' => 'হোয়াটসঅ্যাপ', 'instagram' => 'ইনস্টাগ্রাম', 'call' => 'কল', 'wordpress' => 'ওয়ার্ডপ্রেস', 'others' => 'অন্যান্য'];
    $channelColors = ['website' => 'text-leaf', 'facebook' => 'text-[#1877F2]', 'whatsapp' => 'text-[#25D366]', 'instagram' => 'text-[#E1306C]', 'call' => 'text-ink', 'wordpress' => 'text-[#21759B]', 'others' => 'text-mute'];
@endphp

{{-- mobile: order cards --}}
<div class="lg:hidden space-y-3">
    @forelse ($orders as $order)
        @php [$statusLabel, $statusClass] = $statusMeta[$order->status] ?? [$order->status, 'bg-ink/5 text-ink']; @endphp
        <a href="{{ route('tenant.orders.show', $order) }}"
           class="block bg-white rounded-card border border-ink/5 px-4 py-4 hover:border-leaf/30 active:bg-paper/60 transition min-w-0">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-sm text-leaf break-words">{{ $order->order_number }}</p>
                    <p class="text-xs text-mute mt-0.5">{{ $order->order_date?->format('d M, Y') ?? $order->created_at->format('d M, h:i A') }}</p>
                </div>
                <span class="shrink-0 px-2.5 py-1 rounded-pill text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>

            <div class="mt-2.5 min-w-0">
                <p class="text-sm font-medium break-words">{{ $order->customer_name }}</p>
                <p class="text-xs text-mute break-words">{{ $order->customer_phone }}</p>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-pill text-xs bg-ink/5 {{ $channelColors[$order->channel] ?? 'text-mute' }}">
                    @include('partials.icon', ['platform' => $order->channel, 'class' => 'w-3.5 h-3.5'])
                    <span class="text-ink">{{ $channelLabels[$order->channel] ?? $order->channel }}</span>
                </span>
                @if ($order->messenger_psid)
                    <span class="text-xs px-2 py-0.5 rounded-pill bg-amber/15 text-ink">📩 Messenger</span>
                @endif
                @if ($order->courier_consignment_id)
                    <span class="text-xs px-2 py-0.5 rounded-pill bg-ink/5 text-ink">🚚 {{ ucfirst($order->courier_provider) }}</span>
                @endif
            </div>

            <div class="mt-3 pt-3 border-t border-ink/5 flex items-center justify-between gap-3">
                <p class="text-xs text-mute">{{ $order->items->count() }}টি প্রোডাক্ট</p>
                <p class="font-semibold text-sm shrink-0">{{ number_format($order->total) }}৳</p>
            </div>
        </a>
    @empty
        <div class="bg-white rounded-card border border-ink/5 px-4 py-14 text-center text-mute">
            <i data-lucide="receipt" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
            কোনো অর্ডার নেই।
        </div>
    @endforelse
</div>

{{-- desktop: table --}}
<x-ui.card padding="none" class="hidden lg:block overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-3 py-3"><input type="checkbox" id="selectAll"></th>
            <th class="px-4 py-3">অর্ডার</th><th class="px-4 py-3">কাস্টমার</th>
            <th class="px-4 py-3">উৎস</th><th class="px-4 py-3">মোট</th>
            <th class="px-4 py-3">স্ট্যাটাস</th><th class="px-4 py-3">সময়</th><th class="px-4 py-3"></th>
        </tr></thead>
        <tbody>
        @forelse ($orders as $order)
            @php [$statusLabel, $statusClass] = $statusMeta[$order->status] ?? [$order->status, 'bg-ink/5 text-ink']; @endphp
            <tr class="border-b border-ink/5 last:border-0 hover:bg-paper/60">
                <td class="px-3 py-3">
                    <input type="checkbox" class="order-check" value="{{ $order->id }}">
                </td>
                <td class="px-4 py-3">
                    <a href="{{ route('tenant.orders.show', $order) }}" class="font-medium text-leaf hover:underline">{{ $order->order_number }}</a>
                    @if ($order->courier_consignment_id)<p class="text-[10px] text-mute">🚚 {{ ucfirst($order->courier_provider) }}</p>@endif
                </td>
                <td class="px-4 py-3">{{ $order->customer_name }}<br><span class="text-xs text-mute">{{ $order->customer_phone }}</span></td>
                <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-pill text-xs bg-ink/5 {{ $channelColors[$order->channel] ?? 'text-mute' }}">
                        @include('partials.icon', ['platform' => $order->channel, 'class' => 'w-3.5 h-3.5'])
                        <span class="text-ink">{{ $channelLabels[$order->channel] ?? $order->channel }}</span>
                    </span>
                </td>
                <td class="px-4 py-3 font-semibold">{{ number_format($order->total) }}৳</td>
                <td class="px-4 py-3"><span class="px-2.5 py-1 rounded-pill text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span></td>
                <td class="px-4 py-3 text-xs text-mute">{{ $order->order_date?->format('d M, Y') ?? $order->created_at->format('d M, h:i A') }}</td>
                <td class="px-4 py-3">
                    <button type="button" onclick="quickFraudCheck({{ $order->id }}, '{{ $order->customer_phone }}', this)"
                            class="text-xs text-mute hover:text-ink border border-ink/10 rounded-btn px-2 py-1 transition">🔍 ফ্রড</button>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="px-4 py-14 text-center text-mute">
                <i data-lucide="receipt" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
                কোনো অর্ডার নেই।
            </td></tr>
        @endforelse
        </tbody>
    </table>
</x-ui.card>
<div class="mt-4">{{ $orders->links() }}</div>

@push('scripts')
<script>
    const selectAll = document.getElementById('selectAll');
    const checks = () => document.querySelectorAll('.order-check');
    const bulkBar = document.getElementById('bulkBar');
    const selCount = document.getElementById('selCount');

    function refreshBulkBar() {
        const selected = [...checks()].filter(c => c.checked);
        bulkBar.classList.toggle('hidden', selected.length === 0);
        selCount.textContent = selected.length;

        document.querySelectorAll('#bulkStatusForm input[name="order_ids[]"], #bulkCourierForm input[name="order_ids[]"]').forEach(i => i.remove());
        selected.forEach(c => {
            ['bulkStatusForm', 'bulkCourierForm'].forEach(formId => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'order_ids[]'; inp.value = c.value;
                document.getElementById(formId).appendChild(inp);
            });
        });
    }

    selectAll.addEventListener('change', () => { checks().forEach(c => c.checked = selectAll.checked); refreshBulkBar(); });
    document.addEventListener('change', e => { if (e.target.classList.contains('order-check')) refreshBulkBar(); });

    async function quickFraudCheck(orderId, phone, btn) {
        btn.textContent = '...';
        const res = await fetch('{{ route('tenant.fraud.check') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ phone }),
        }).then(r => r.json()).catch(() => null);

        const map = { new: '🆕 নতুন', safe: '✅ নিরাপদ', risky: '⚠️ ঝুঁকি', danger: '🚫 বিপজ্জনক', error: '⚠️ সমস্যা', unconfigured: '⚠️ সেট নেই' };
        btn.textContent = (res && map[res.verdict]) || 'এরর';
    }
</script>
@endpush
@endsection
