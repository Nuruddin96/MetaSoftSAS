@extends('layouts.panel')

@section('title', $order->order_number)

@section('content')
<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
    <div class="min-w-0">
        <h1 class="font-disp font-bold text-xl sm:text-2xl flex flex-wrap items-center gap-2 break-words">
            {{ $order->order_number }}
            @if ($order->status === 'pending' && $order->source === 'messenger')
                <span class="text-xs px-2.5 py-1 rounded-pill font-semibold bg-amber/15 text-ink">📩 মেসেঞ্জার থেকে — পেন্ডিং</span>
            @endif
        </h1>
        <p class="text-xs text-mute mt-1">{{ $order->order_date?->format('d M Y') ?? $order->created_at->format('d M Y, h:i A') }}</p>
    </div>
    <a href="{{ route('tenant.orders.index') }}" class="shrink-0 text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← সব অর্ডার</a>
</div>

{{--
    Every card below is a direct child of one flex/grid container so each
    can carry its own position — order-N (mobile stacking, per the
    Customer/Order/Products/Status/Courier/Messenger hierarchy) and
    lg:col-start-*/lg:row-start-* (reconstructing the original two-column
    desktop placement). This avoids rendering any card twice: a duplicated
    "complete order" form in particular would collide on its own DOM ids
    (completeForm, itemRows, ...) and break the product-picker JS below.
--}}
<div class="flex flex-col gap-4 sm:gap-6 lg:grid lg:grid-cols-3 lg:gap-6 lg:items-start">

    {{-- CUSTOMER --}}
    <div class="order-1 lg:order-none lg:col-start-3 lg:row-start-2 min-w-0">
        <x-ui.card padding="sm" class="text-sm space-y-2">
            <p class="font-bold mb-1">কাস্টমার</p>
            <p class="break-words">{{ $order->customer_name }}</p>
            <p class="flex items-center gap-2">
                <a href="tel:{{ $order->customer_phone }}" class="text-leaf font-medium">{{ $order->customer_phone }}</a>
                <button type="button" class="copy-btn shrink-0 text-xs text-mute hover:text-ink border border-ink/10 rounded-btn px-2 py-1 transition" data-copy="{{ $order->customer_phone }}">কপি</button>
            </p>
            <p class="flex items-start gap-2">
                <span class="text-mute break-words flex-1 min-w-0">{{ $order->customer_address }}</span>
                <button type="button" class="copy-btn shrink-0 text-xs text-mute hover:text-ink border border-ink/10 rounded-btn px-2 py-1 transition" data-copy="{{ $order->customer_address }}">কপি</button>
            </p>
            <p class="text-xs text-mute pt-1">পেমেন্ট: {{ strtoupper($order->payment_method) }}</p>
        </x-ui.card>
    </div>

    {{-- ORDER — channel/source --}}
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
    <div class="order-2 lg:order-none lg:col-start-3 lg:row-start-1 min-w-0">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3 flex items-center gap-2 {{ $channelColors[$order->channel] ?? 'text-mute' }}">
                @include('partials.icon', ['platform' => $order->channel, 'class' => 'w-5 h-5'])
                <span class="text-ink">অর্ডারের উৎস</span>
            </p>
            <form method="POST" action="{{ route('tenant.orders.channel', $order) }}">
                @csrf
                <select name="channel" onchange="this.form.submit()" class="w-full rounded-btn border border-ink/15 px-3 py-3 text-sm bg-white">
                    @foreach ($channelMeta as $key => $label)
                        <option value="{{ $key }}" @selected($order->channel === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </x-ui.card>
    </div>

    {{-- PRODUCTS + AMOUNT --}}
    <div class="order-3 lg:order-none lg:col-span-2 lg:row-start-1 min-w-0">
        @if ($order->items->isNotEmpty())
            <x-ui.card padding="none" class="min-w-0">
                <div class="px-5 py-3.5 border-b border-ink/5 font-bold text-sm">আইটেম</div>

                {{-- desktop: table --}}
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full text-sm min-w-[420px]">
                        <tbody>
                        @foreach ($order->items as $item)
                            <tr class="border-b border-ink/5 last:border-0">
                                <td class="px-5 py-3">
                                    {{ $item->product_name }}
                                    @if ($item->variant_name && $item->variant_name !== 'Default')
                                        <span class="text-mute text-xs">({{ $item->variant_name }})</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-mute whitespace-nowrap">{{ $item->quantity }} × {{ number_format($item->unit_price) }}৳</td>
                                <td class="px-5 py-3 text-right font-medium whitespace-nowrap">{{ number_format($item->line_total) }}৳</td>
                            </tr>
                        @endforeach
                        <tr><td colspan="2" class="px-5 py-2 text-right text-mute">সাবটোটাল</td>
                            <td class="px-5 py-2 text-right whitespace-nowrap">{{ number_format($order->subtotal) }}৳</td></tr>
                        @if ($order->discount > 0)
                        <tr><td colspan="2" class="px-5 py-2 text-right text-mute">ডিসকাউন্ট</td>
                            <td class="px-5 py-2 text-right whitespace-nowrap">-{{ number_format($order->discount) }}৳</td></tr>
                        @endif
                        <tr><td colspan="2" class="px-5 py-2 text-right text-mute">ডেলিভারি চার্জ</td>
                            <td class="px-5 py-2 text-right whitespace-nowrap">{{ number_format($order->delivery_charge) }}৳</td></tr>
                        <tr class="font-bold text-base"><td colspan="2" class="px-5 py-3 text-right">মোট</td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">{{ number_format($order->total) }}৳</td></tr>
                        </tbody>
                    </table>
                </div>

                {{-- mobile: stacked product cards --}}
                <div class="lg:hidden divide-y divide-ink/5">
                    @foreach ($order->items as $item)
                        <div class="px-4 py-3 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium break-words">{{ $item->product_name }}</p>
                                @if ($item->variant_name && $item->variant_name !== 'Default')
                                    <p class="text-xs text-mute">{{ $item->variant_name }}</p>
                                @endif
                                <p class="text-xs text-mute mt-0.5">{{ $item->quantity }} × {{ number_format($item->unit_price) }}৳</p>
                            </div>
                            <p class="text-sm font-semibold shrink-0 whitespace-nowrap">{{ number_format($item->line_total) }}৳</p>
                        </div>
                    @endforeach
                    <div class="px-4 py-2 flex justify-between text-sm text-mute"><span>সাবটোটাল</span><span>{{ number_format($order->subtotal) }}৳</span></div>
                    @if ($order->discount > 0)
                        <div class="px-4 py-2 flex justify-between text-sm text-mute"><span>ডিসকাউন্ট</span><span>-{{ number_format($order->discount) }}৳</span></div>
                    @endif
                    <div class="px-4 py-2 flex justify-between text-sm text-mute"><span>ডেলিভারি চার্জ</span><span>{{ number_format($order->delivery_charge) }}৳</span></div>
                    <div class="px-4 py-3 flex justify-between font-bold text-base"><span>মোট</span><span>{{ number_format($order->total) }}৳</span></div>
                </div>
            </x-ui.card>
        @else
            <x-ui.card class="min-w-0">
                <p class="font-bold text-sm mb-1">অর্ডার সম্পূর্ণ করুন</p>
                <p class="text-xs text-mute mb-4">প্রোডাক্ট এখনো বাছাই করা হয়নি — প্রোডাক্ট/ভ্যারিয়ান্ট বাছাই করে, দাম নিশ্চিত করে অর্ডার কনফার্ম করুন।</p>

                <form method="POST" action="{{ route('tenant.orders.complete', $order) }}" id="completeForm" class="space-y-4">
                    @csrf
                    {{-- Messenger orders arrive with only whatever CustomerInfoExtractor
                         found in the conversation text — often no division/district at
                         all. Same fields the manual order-create form already collects
                         (tenant/orders/create.blade.php), added here so a Messenger order
                         can be confirmed with complete delivery info, not just name/phone/
                         address text. --}}
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">নাম</label>
                            <input name="customer_name" value="{{ old('customer_name', $order->customer_name) }}" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 focus:ring-2 focus:ring-leaf outline-none">
                        </div>
                        <div>
                            <label class="text-sm font-medium">মোবাইল নাম্বার</label>
                            <input name="customer_phone" value="{{ old('customer_phone', $order->customer_phone) }}" placeholder="01XXXXXXXXX" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 focus:ring-2 focus:ring-leaf outline-none">
                        </div>
                    </div>
                    <div>
                        <label class="text-sm font-medium">ঠিকানা</label>
                        <textarea name="customer_address" rows="2" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 focus:ring-2 focus:ring-leaf outline-none">{{ old('customer_address', $order->customer_address) }}</textarea>
                    </div>
                    <div>
                        <label class="text-sm font-medium">অর্ডারের তারিখ</label>
                        <input type="date" name="order_date" value="{{ old('order_date', optional($order->order_date)->format('Y-m-d') ?? now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" class="mt-1 w-full max-w-full rounded-btn border border-ink/15 px-3 py-3 focus:ring-2 focus:ring-leaf outline-none">
                    </div>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm font-medium">বিভাগ</label>
                            <select name="division_id" id="divisionSelect" onchange="calcTotal()" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 bg-white">
                                <option value="">— নেই —</option>
                                @foreach ($divisions as $d)<option value="{{ $d->id }}" @selected(old('division_id', $order->division_id) == $d->id)>{{ $d->bn_name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">জেলা</label>
                            <select name="district_id" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 bg-white">
                                <option value="">— নেই —</option>
                                @foreach ($districts as $d)<option value="{{ $d->id }}" @selected(old('district_id', $order->district_id) == $d->id)>{{ $d->bn_name }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                            <p class="font-bold text-sm">প্রোডাক্ট</p>
                            <button type="button" onclick="addRow()" class="text-sm text-leaf font-semibold hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">+ প্রোডাক্ট যোগ করুন</button>
                        </div>
                        <div id="itemRows" class="space-y-3"></div>
                        <p id="noItemMsg" class="text-sm text-mute text-center py-6">উপরের বাটনে ক্লিক করে প্রোডাক্ট যোগ করুন</p>
                        <div class="mt-4 pt-4 border-t border-ink/10 space-y-1.5 text-sm">
                            <div class="flex justify-between text-mute"><span>প্রোডাক্ট সাবটোটাল</span><span id="subtotalShow">0৳</span></div>
                            <div class="flex justify-between text-mute"><span>ডেলিভারি চার্জ</span><span id="deliveryChargeShow">0৳</span></div>
                            <div class="flex justify-between font-bold text-base pt-1.5 border-t border-ink/10"><span>মোট টাকা</span><span id="grandTotalShow">0৳</span></div>
                        </div>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4 pt-2 border-t border-ink/10">
                        <div>
                            <label class="text-sm font-medium">পেমেন্ট পদ্ধতি</label>
                            <select name="payment_method" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 bg-white">
                                <option value="cod">ক্যাশ অন ডেলিভারি</option>
                                <option value="cash">ক্যাশ</option>
                                <option value="bkash">বিকাশ</option>
                                <option value="nagad">নগদ</option>
                                <option value="bank">ব্যাংক</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium">ডিসকাউন্ট</label>
                            <input name="discount" id="discountInput" type="number" step="0.01" min="0" value="0" oninput="calcTotal()" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-3 focus:ring-2 focus:ring-leaf outline-none">
                        </div>
                    </div>
                    {{-- No manual delivery-charge field here either — same
                         DeliveryChargeService-driven, division-based
                         auto-calculation as the New Order form. --}}
                    <p class="text-xs text-mute">ডেলিভারি চার্জ বিভাগ অনুযায়ী স্বয়ংক্রিয়ভাবে হিসাব হয় — <a href="{{ route('tenant.settings') }}" class="text-leaf hover:underline">সেটিংসে বদলান</a>।</p>

                    @if ($errors->any())
                        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-btn p-3">
                            <ul class="list-disc ml-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <x-ui.button type="submit" variant="accent" size="lg" class="w-full">✓ অর্ডার কনফার্ম করুন</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>

    {{-- ORDER STATUS --}}
    <div class="order-4 lg:order-none lg:col-start-3 lg:row-start-3 min-w-0">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">স্ট্যাটাস বদলান</p>
            <form method="POST" action="{{ route('tenant.orders.status', $order) }}" class="space-y-3">
                @csrf
                <select name="status" class="w-full rounded-btn border border-ink/15 px-3 py-3 text-sm bg-white">
                    @foreach (['pending' => 'পেন্ডিং', 'confirmed' => 'কনফার্মড', 'processing' => 'প্রসেসিং', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'ক্যান্সেলড', 'returned' => 'রিটার্নড'] as $key => $label)
                        <option value="{{ $key }}" @selected($order->status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit" variant="accent" size="default" class="w-full">আপডেট</x-ui.button>
            </form>
        </x-ui.card>
    </div>

    {{-- COURIER — kept visually separate from order status above --}}
    <div class="order-6 lg:order-none lg:col-start-3 lg:row-start-5 min-w-0">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">🚚 কুরিয়ার</p>
            @if ($order->courier_consignment_id)
                <p class="text-sm">{{ ucfirst($order->courier_provider) }}-এ পাঠানো হয়েছে ✓</p>
                <p class="text-xs text-mute mt-1 break-words">কনসাইনমেন্ট: {{ $order->courier_consignment_id }}<br>
                    ট্র্যাকিং: {{ $order->courier_tracking_code }}</p>
                @if ($order->courier_status)
                    <p class="text-xs text-mute mt-2 pt-2 border-t border-ink/10">কুরিয়ার স্ট্যাটাস: <span class="font-medium text-ink">{{ $order->courier_status }}</span></p>
                @endif
                <form method="POST" action="{{ route('tenant.orders.courier.refresh', $order) }}" class="mt-2">
                    @csrf
                    <button type="submit" class="w-full py-2.5 rounded-btn border border-ink/15 font-semibold text-xs hover:bg-paper transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">🔄 স্ট্যাটাস রিফ্রেশ করুন</button>
                </form>
            @else
                <form method="POST" action="{{ route('tenant.orders.courier', $order) }}" class="space-y-3">
                    @csrf
                    <select name="provider" class="w-full rounded-btn border border-ink/15 px-3 py-3 text-sm bg-white">
                        <option value="steadfast">Steadfast</option>
                        <option value="pathao">Pathao</option>
                    </select>
                    <button class="w-full py-3 rounded-btn bg-ink text-white font-semibold text-sm hover:bg-ink/90 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2"
                            onclick="return confirm('অর্ডারটি কুরিয়ারে পাঠাবেন?')">কুরিয়ারে পাঠান</button>
                </form>
                <p class="text-xs text-mute mt-2">API সেটিংস না দেয়া থাকলে <a href="{{ route('tenant.settings') }}" class="text-leaf hover:underline">সেটিংস পেজে</a> দিন।</p>
            @endif
        </x-ui.card>
    </div>

    {{-- MESSENGER --}}
    @if ($messengerMessages->isNotEmpty())
        <div class="order-7 lg:order-none lg:col-span-2 lg:row-start-2 min-w-0">
            <x-ui.card padding="none" class="min-w-0">
                <div class="px-5 py-3.5 border-b border-ink/5 font-bold text-sm flex flex-wrap items-center justify-between gap-2">
                    <span>📩 Messenger থেকে অর্ডার</span>
                    <a href="{{ route('tenant.messenger.show', $order->messenger_psid) }}" class="shrink-0 text-xs font-normal text-leaf hover:underline">মেসেঞ্জার কথোপকথন দেখুন →</a>
                </div>
                <div class="p-3 sm:p-5 min-w-0">
                    @include('tenant.messenger._thread', ['messages' => $messengerMessages])
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- fraud check — appears right after Status, before Courier and Message --}}
    <div class="order-5 lg:order-none lg:col-start-3 lg:row-start-4 min-w-0">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">🔍 ফ্রড চেক</p>
            <button onclick="fraudCheck()" id="fraudBtn"
                    class="w-full py-3 rounded-btn border border-ink/15 font-semibold text-sm hover:bg-paper transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                {{ $order->customer_phone }} — চেক করুন
            </button>
            <div id="fraudResult" class="hidden mt-3 text-sm rounded-btn p-3"></div>
        </x-ui.card>
    </div>

    @if ($order->note)
        <div class="order-8 lg:order-none lg:col-span-2 lg:row-start-3 min-w-0">
            <x-ui.card tone="amber" padding="sm" class="text-sm break-words"><b>নোট:</b> {{ $order->note }}</x-ui.card>
        </div>
    @endif
</div>

@push('scripts')
<script>
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!btn.dataset.copy || !navigator.clipboard) return;
            navigator.clipboard.writeText(btn.dataset.copy).then(() => {
                const original = btn.textContent;
                btn.textContent = '✓';
                setTimeout(() => { btn.textContent = original; }, 1200);
            });
        });
    });

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
        const internal = res.internal
            ? `<p class="text-xs mt-2 pt-2 border-t border-ink/10">এই শপের নিজস্ব অর্ডার: ${res.internal.total} · ডেলিভারড: ${res.internal.delivered} · বাতিল: ${res.internal.cancelled} · রিটার্ন: ${res.internal.returned} · পেন্ডিং: ${res.internal.pending}</p>`
            : '';
        box.innerHTML = `<p class="font-bold">${label}${res.success_ratio !== null ? ' — সাকসেস ' + res.success_ratio + '%' : ''}</p>
            <p class="text-xs mt-1">মোট অর্ডার (কুরিয়ার): ${res.total} · ডেলিভারড: ${res.delivered} · রিটার্ন: ${res.returned}</p>
            ${internal}
            <p class="text-[10px] text-mute mt-1">যে কুরিয়ারের API যুক্ত আছে শুধু তার ডেটা দেখায়</p>`;
    }
</script>
@if ($order->items->isEmpty())
<script>
    const products = @json($productsJson);
    const dhakaDivisionId = @json($dhakaDivisionId);
    const chargeInside = @json($chargeInside);
    const chargeOutside = @json($chargeOutside);
    let rowIdx = 0;

    /** No manual delivery-charge input exists — same client-side-preview-only computation as tenant/orders/create.blade.php; the server independently recomputes from division_id and never trusts a submitted amount. */
    function currentDeliveryCharge() {
        const divisionId = parseInt(document.getElementById('divisionSelect').value, 10) || null;
        return divisionId === dhakaDivisionId ? chargeInside : chargeOutside;
    }

    function addRow() {
        document.getElementById('noItemMsg').style.display = 'none';
        const wrap = document.getElementById('itemRows');
        const div = document.createElement('div');
        div.className = 'flex flex-col sm:flex-row gap-3 sm:items-center border border-ink/10 sm:border-0 rounded-lg p-3 sm:p-0';
        div.id = 'row' + rowIdx;

        div.innerHTML = `
            <div class="w-full sm:flex-1 flex items-center gap-2">
                <img class="prodThumb w-9 h-9 rounded-lg object-cover border border-ink/10 shrink-0 hidden">
                <div class="flex-1 min-w-0">
                    <input type="text" class="prodSearch w-full rounded-lg border border-ink/15 px-3 py-2 text-sm mb-1 bg-white" placeholder="প্রোডাক্ট খুঁজুন..." oninput="filterProducts(${rowIdx})" autocomplete="off">
                    <select class="prodSelect w-full rounded-lg border border-ink/15 px-3 py-3 sm:py-2 text-sm bg-white" onchange="updateVariants(${rowIdx})">
                        <option value="">প্রোডাক্ট বাছাই করুন</option>
                    </select>
                </div>
            </div>
            <select class="variantSelect w-full sm:w-48 rounded-lg border border-ink/15 px-3 py-3 sm:py-2 text-sm bg-white" onchange="calcTotal()"></select>
            <div class="flex items-center gap-3">
                <input type="number" class="qtyInput w-20 shrink-0 rounded-lg border border-ink/15 px-3 py-3 sm:py-2 text-sm" value="1" min="1" onchange="calcTotal()">
                <span class="lineTotal flex-1 sm:flex-none sm:w-24 text-right text-sm font-semibold">0৳</span>
                <button type="button" onclick="removeRow(${rowIdx})" class="shrink-0 text-red-600 text-sm px-2 py-1" aria-label="প্রোডাক্ট মুছুন">✕</button>
            </div>
        `;
        wrap.appendChild(div);
        renderProductOptions(rowIdx, '');
        rowIdx++;
    }

    /** Rebuilds a row's <select> options from `products`, filtered by a case-insensitive substring match on name OR any variant's SKU against the search box — option `value` stays the original index into `products` (never re-indexed), so updateVariants()/calcTotal() need no changes. */
    function renderProductOptions(idx, query) {
        const row = document.getElementById('row' + idx);
        const select = row.querySelector('.prodSelect');
        const q = query.trim().toLowerCase();
        const previouslySelected = select.value;

        const matches = products
            .map((p, pi) => [p, pi])
            .filter(([p]) => q === ''
                || p.name.toLowerCase().includes(q)
                || (p.variants || []).some(v => (v.sku || '').toLowerCase().includes(q)));

        select.innerHTML = '<option value="">প্রোডাক্ট বাছাই করুন</option>'
            + matches.map(([p, pi]) => `<option value="${pi}">${p.name}</option>`).join('');

        if (matches.some(([, pi]) => String(pi) === previouslySelected)) {
            select.value = previouslySelected;
        }
    }

    function filterProducts(idx) {
        const row = document.getElementById('row' + idx);
        renderProductOptions(idx, row.querySelector('.prodSearch').value);
    }

    function updateVariants(idx) {
        const row = document.getElementById('row' + idx);
        const pi = row.querySelector('.prodSelect').value;
        const variantSelect = row.querySelector('.variantSelect');
        const thumb = row.querySelector('.prodThumb');
        variantSelect.innerHTML = '';
        if (pi === '') {
            thumb.classList.add('hidden');
            calcTotal();
            return;
        }
        if (products[pi].image) {
            thumb.src = '/storage/' + products[pi].image;
            thumb.classList.remove('hidden');
        } else {
            thumb.classList.add('hidden');
        }
        products[pi].variants.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.id; opt.dataset.price = v.price;
            opt.textContent = `${v.name} — ${v.price}৳`;
            variantSelect.appendChild(opt);
        });
        calcTotal();
    }

    function removeRow(idx) {
        document.getElementById('row' + idx)?.remove();
        if (!document.getElementById('itemRows').children.length) document.getElementById('noItemMsg').style.display = 'block';
        calcTotal();
    }

    function calcTotal() {
        let subtotal = 0;
        document.querySelectorAll('#itemRows > div').forEach(row => {
            const variantSelect = row.querySelector('.variantSelect');
            const qty = parseInt(row.querySelector('.qtyInput').value) || 0;
            const opt = variantSelect.options[variantSelect.selectedIndex];
            const price = opt ? parseFloat(opt.dataset.price || 0) : 0;
            const lineTotal = price * qty;
            row.querySelector('.lineTotal').textContent = lineTotal.toLocaleString() + '৳';
            subtotal += lineTotal;
        });
        document.getElementById('subtotalShow').textContent = subtotal.toLocaleString() + '৳';

        const delivery = currentDeliveryCharge();
        const discount = Math.min(parseFloat(document.getElementById('discountInput').value) || 0, subtotal);
        document.getElementById('deliveryChargeShow').textContent = delivery.toLocaleString() + '৳';
        document.getElementById('grandTotalShow').textContent = (subtotal - discount + delivery).toLocaleString() + '৳';
    }

    document.getElementById('completeForm').addEventListener('submit', function (e) {
        document.querySelectorAll('#itemRows > div').forEach(row => {
            const variantSelect = row.querySelector('.variantSelect');
            if (variantSelect.value) {
                const vi = document.createElement('input');
                vi.type = 'hidden'; vi.name = 'variant_ids[]'; vi.value = variantSelect.value;
                this.appendChild(vi);
                const qi = document.createElement('input');
                qi.type = 'hidden'; qi.name = 'quantities[]'; qi.value = row.querySelector('.qtyInput').value;
                this.appendChild(qi);
            }
        });
    });

    addRow(); // start with one row
    calcTotal(); // initializes the delivery-charge preview from any division_id already on this order (e.g. from a Messenger conversation)
</script>
@endif
@endpush
@endsection
