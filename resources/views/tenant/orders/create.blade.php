@extends('layouts.panel')
@section('title', 'নতুন অর্ডার')
@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">নতুন অর্ডার (ম্যানুয়াল)</h1>
    <a href="{{ route('tenant.orders.index') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← সব অর্ডার</a>
</div>

<form method="POST" action="{{ route('tenant.orders.store') }}" class="max-w-3xl space-y-6" id="orderForm">
    @csrf

    <x-ui.card class="space-y-4">
        <p class="font-bold text-sm">কাস্টমারের তথ্য</p>
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">নাম *</label>
                <input name="customer_name" value="{{ old('customer_name', request('name')) }}" required class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div>
                <label class="text-sm font-medium">মোবাইল নাম্বার *</label>
                <input name="customer_phone" required placeholder="01XXXXXXXXX" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            </div>
        </div>
        <div>
            <label class="text-sm font-medium">ঠিকানা</label>
            <textarea name="customer_address" rows="2" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none"></textarea>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">বিভাগ</label>
                <select name="division_id" id="divisionSelect" onchange="calcTotal()" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                    <option value="">— নেই —</option>
                    @foreach ($divisions as $d)<option value="{{ $d->id }}">{{ $d->bn_name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">জেলা</label>
                <select name="district_id" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                    <option value="">— নেই —</option>
                    @foreach ($districts as $d)<option value="{{ $d->id }}">{{ $d->bn_name }}</option>@endforeach
                </select>
            </div>
        </div>
        <div>
            <label class="text-sm font-medium">অর্ডারের উৎস</label>
            <select name="channel" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                <option value="call" @selected(request('channel')==='call')>📞 কল</option>
                <option value="facebook" @selected(request('channel')==='facebook')>📘 ফেসবুক</option>
                <option value="whatsapp" @selected(request('channel')==='whatsapp')>💬 হোয়াটসঅ্যাপ</option>
                <option value="instagram" @selected(request('channel')==='instagram')>📷 ইনস্টাগ্রাম</option>
                <option value="website" @selected(request('channel')==='website')>🌐 ওয়েবসাইট</option>
                <option value="others" @selected(request('channel')==='others')>📦 অন্যান্য</option>
            </select>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="flex items-center justify-between mb-3">
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
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <p class="font-bold text-sm">পেমেন্ট</p>
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">পেমেন্ট পদ্ধতি</label>
                <select name="payment_method" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                    <option value="cod">ক্যাশ অন ডেলিভারি</option>
                    <option value="cash">ক্যাশ</option>
                    <option value="bkash">বিকাশ</option>
                    <option value="nagad">নগদ</option>
                    <option value="bank">ব্যাংক</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">ডিসকাউন্ট</label>
                <input name="discount" id="discountInput" type="number" step="0.01" min="0" value="0" oninput="calcTotal()" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            </div>
        </div>
        {{-- No manual delivery-charge field — Settings → ডেলিভারি চার্জ
             configures Inside/Outside Dhaka amounts once; this order applies
             the right one automatically from the selected বিভাগ above (see
             DeliveryChargeService), shown live in the প্রোডাক্ট card's summary. --}}
        <p class="text-xs text-mute">ডেলিভারি চার্জ বিভাগ অনুযায়ী স্বয়ংক্রিয়ভাবে হিসাব হয় — <a href="{{ route('tenant.settings') }}" class="text-leaf hover:underline">সেটিংসে বদলান</a>।</p>
        <div>
            <label class="text-sm font-medium">নোট</label>
            <input name="note" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
    </x-ui.card>

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-btn p-3">
            <ul class="list-disc ml-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <x-ui.button type="submit" variant="accent" size="lg">অর্ডার তৈরি করুন</x-ui.button>
</form>

@push('scripts')
<script>
    const products = @json($productsJson);
    const dhakaDivisionId = @json($dhakaDivisionId);
    const chargeInside = @json($chargeInside);
    const chargeOutside = @json($chargeOutside);

    let rowIdx = 0;

    function addRow() {
        document.getElementById('noItemMsg').style.display = 'none';
        const wrap = document.getElementById('itemRows');
        const div = document.createElement('div');
        // Stacks vertically on mobile (flex-col), horizontal from sm: up —
        // qty/price/remove grouped in their own row so they never overflow
        // off-screen next to a long product name on narrow viewports.
        div.className = 'flex flex-col sm:flex-row gap-3 sm:items-center border border-ink/10 sm:border-0 rounded-lg p-3 sm:p-0';
        div.id = 'row' + rowIdx;

        let productOptions = products.map((p, pi) => `<option value="${pi}">${p.name}</option>`).join('');

        div.innerHTML = `
            <select class="prodSelect w-full sm:flex-1 rounded-lg border border-ink/15 px-3 py-3 sm:py-2 text-sm bg-white" onchange="updateVariants(${rowIdx})">
                <option value="">প্রোডাক্ট বাছাই করুন</option>${productOptions}
            </select>
            <select class="variantSelect w-full sm:w-48 rounded-lg border border-ink/15 px-3 py-3 sm:py-2 text-sm bg-white" onchange="calcTotal()"></select>
            <div class="flex items-center gap-3">
                <input type="number" class="qtyInput w-20 shrink-0 rounded-lg border border-ink/15 px-3 py-3 sm:py-2 text-sm" value="1" min="1" onchange="calcTotal()">
                <span class="lineTotal flex-1 sm:flex-none sm:w-24 text-right text-sm font-semibold">0৳</span>
                <button type="button" onclick="removeRow(${rowIdx})" class="shrink-0 text-red-600 text-sm px-2 py-1" aria-label="প্রোডাক্ট মুছুন">✕</button>
            </div>
        `;
        wrap.appendChild(div);
        rowIdx++;
    }

    /** No manual delivery-charge input exists at all — this is the only place the amount is decided client-side, purely for the live summary; the server independently recomputes the same way from division_id, never trusting anything submitted for it. */
    function currentDeliveryCharge() {
        const divisionId = parseInt(document.getElementById('divisionSelect').value, 10) || null;
        return divisionId === dhakaDivisionId ? chargeInside : chargeOutside;
    }

    function updateVariants(idx) {
        const row = document.getElementById('row' + idx);
        const pi = row.querySelector('.prodSelect').value;
        const variantSelect = row.querySelector('.variantSelect');
        variantSelect.innerHTML = '';
        if (pi === '') { calcTotal(); return; }
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

    document.getElementById('orderForm').addEventListener('submit', function (e) {
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
    calcTotal(); // initializes the delivery-charge preview even before any product/division interaction
</script>
@endpush
@endsection
