@extends('layouts.panel')

@section('title', $order->order_number)

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">{{ $order->order_number }}</h1>
    <a href="{{ route('tenant.orders.index') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← সব অর্ডার</a>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">

        <x-ui.card padding="none">
            <div class="px-5 py-3.5 border-b border-ink/5 font-bold text-sm">আইটেম</div>
            <table class="w-full text-sm">
                <tbody>
                @foreach ($order->items as $item)
                    <tr class="border-b border-ink/5 last:border-0">
                        <td class="px-5 py-3">
                            {{ $item->product_name }}
                            @if ($item->variant_name && $item->variant_name !== 'Default')
                                <span class="text-mute text-xs">({{ $item->variant_name }})</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-mute">{{ $item->quantity }} × {{ number_format($item->unit_price) }}৳</td>
                        <td class="px-5 py-3 text-right font-medium">{{ number_format($item->line_total) }}৳</td>
                    </tr>
                @endforeach
                <tr><td colspan="2" class="px-5 py-2 text-right text-mute">ডেলিভারি চার্জ</td>
                    <td class="px-5 py-2 text-right">{{ number_format($order->delivery_charge) }}৳</td></tr>
                <tr class="font-bold text-base"><td colspan="2" class="px-5 py-3 text-right">মোট</td>
                    <td class="px-5 py-3 text-right">{{ number_format($order->total) }}৳</td></tr>
                </tbody>
            </table>
        </x-ui.card>

        @if ($order->note)
            <x-ui.card tone="amber" padding="sm" class="text-sm"><b>নোট:</b> {{ $order->note }}</x-ui.card>
        @endif
    </div>

    <div class="space-y-6">
        @php
            $channelMeta = [
                'website'   => 'ওয়েবসাইট',
                'facebook'  => 'ফেসবুক',
                'whatsapp'  => 'হোয়াটসঅ্যাপ',
                'instagram' => 'ইনস্টাগ্রাম',
                'call'      => 'কল',
                'others'    => 'অন্যান্য',
            ];
            $channelColors = ['website' => 'text-leaf', 'facebook' => 'text-[#1877F2]', 'whatsapp' => 'text-[#25D366]', 'instagram' => 'text-[#E1306C]', 'call' => 'text-ink', 'others' => 'text-mute'];
        @endphp
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3 flex items-center gap-2 {{ $channelColors[$order->channel] ?? 'text-mute' }}">
                @include('partials.icon', ['platform' => $order->channel, 'class' => 'w-5 h-5'])
                <span class="text-ink">অর্ডারের উৎস</span>
            </p>
            <form method="POST" action="{{ route('tenant.orders.channel', $order) }}">
                @csrf
                <select name="channel" onchange="this.form.submit()" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm bg-white">
                    @foreach ($channelMeta as $key => $label)
                        <option value="{{ $key }}" @selected($order->channel === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </x-ui.card>

        <x-ui.card padding="sm" class="text-sm space-y-1.5">
            <p class="font-bold mb-2">কাস্টমার</p>
            <p>{{ $order->customer_name }}</p>
            <p><a href="tel:{{ $order->customer_phone }}" class="text-leaf font-medium">{{ $order->customer_phone }}</a></p>
            <p class="text-mute">{{ $order->customer_address }}</p>
            <p class="text-xs text-mute pt-1">পেমেন্ট: {{ strtoupper($order->payment_method) }} · {{ $order->created_at->format('d M Y, h:i A') }}</p>
        </x-ui.card>

        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">স্ট্যাটাস বদলান</p>
            <form method="POST" action="{{ route('tenant.orders.status', $order) }}" class="space-y-3">
                @csrf
                <select name="status" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm bg-white">
                    @foreach (['pending' => 'পেন্ডিং', 'confirmed' => 'কনফার্মড', 'processing' => 'প্রসেসিং', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'ক্যান্সেলড', 'returned' => 'রিটার্নড'] as $key => $label)
                        <option value="{{ $key }}" @selected($order->status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit" variant="accent" size="sm" class="w-full">আপডেট</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">🔍 ফ্রড চেক</p>
            <button onclick="fraudCheck()" id="fraudBtn"
                    class="w-full py-2.5 rounded-btn border border-ink/15 font-semibold text-sm hover:bg-paper transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                {{ $order->customer_phone }} — চেক করুন
            </button>
            <div id="fraudResult" class="hidden mt-3 text-sm rounded-btn p-3"></div>
        </x-ui.card>

        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">🚚 কুরিয়ার</p>
            @if ($order->courier_consignment_id)
                <p class="text-sm">{{ ucfirst($order->courier_provider) }}-এ পাঠানো হয়েছে ✓</p>
                <p class="text-xs text-mute mt-1">কনসাইনমেন্ট: {{ $order->courier_consignment_id }}<br>
                    ট্র্যাকিং: {{ $order->courier_tracking_code }}</p>
            @else
                <form method="POST" action="{{ route('tenant.orders.courier', $order) }}" class="space-y-3">
                    @csrf
                    <select name="provider" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm bg-white">
                        <option value="steadfast">Steadfast</option>
                        <option value="pathao">Pathao</option>
                    </select>
                    <button class="w-full py-2.5 rounded-btn bg-ink text-white font-semibold text-sm hover:bg-ink/90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2"
                            onclick="return confirm('অর্ডারটি কুরিয়ারে পাঠাবেন?')">কুরিয়ারে পাঠান</button>
                </form>
                <p class="text-xs text-mute mt-2">API সেটিংস না দেয়া থাকলে <a href="{{ route('tenant.settings') }}" class="text-leaf hover:underline">সেটিংস পেজে</a> দিন।</p>
            @endif
        </x-ui.card>
    </div>
</div>

@push('scripts')
<script>
    async function fraudCheck() {
        const btn = document.getElementById('fraudBtn');
        const box = document.getElementById('fraudResult');
        btn.textContent = 'চেক হচ্ছে...';
        btn.disabled = true;

        const res = await fetch('{{ route('tenant.fraud.check') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ phone: '{{ $order->customer_phone }}' }),
        }).then(r => r.json()).catch(() => null);

        btn.disabled = false;
        btn.textContent = '{{ $order->customer_phone }} — আবার চেক করুন';
        box.classList.remove('hidden');

        if (!res) { box.className = 'mt-3 text-sm rounded-lg p-3 bg-red-50 border border-red-200'; box.textContent = 'চেক করা যায়নি।'; return; }

        if (res.verdict === 'error' || res.verdict === 'unconfigured') {
            box.className = 'mt-3 text-sm rounded-lg p-3 bg-amber/15 border border-amber/40';
            box.innerHTML = `<p class="font-bold">ফ্রড চেক করা যায়নি</p><p class="text-xs mt-1">${res.message}</p>`;
            return;
        }

        const styles = {
            new:    ['bg-ink/5 border border-ink/10', '🆕 নতুন কাস্টমার — আগের কোনো রেকর্ড নেই'],
            safe:   ['bg-leaf/10 border border-leaf/30', '✅ নিরাপদ'],
            risky:  ['bg-amber/15 border border-amber/40', '⚠️ ঝুঁকিপূর্ণ'],
            danger: ['bg-red-50 border border-red-200', '🚫 বিপজ্জনক'],
        };
        const [cls, label] = styles[res.verdict] || styles.new;
        box.className = 'mt-3 text-sm rounded-lg p-3 ' + cls;
        box.innerHTML = `<p class="font-bold">${label}${res.success_ratio !== null ? ' — সাকসেস ' + res.success_ratio + '%' : ''}</p>
            <p class="text-xs mt-1">মোট অর্ডার: ${res.total} · ডেলিভারড: ${res.delivered} · রিটার্ন: ${res.returned}</p>
            <p class="text-[10px] text-mute mt-1">যে কুরিয়ারের API যুক্ত আছে শুধু তার ডেটা দেখায়</p>`;
    }
</script>
@endpush
@endsection
