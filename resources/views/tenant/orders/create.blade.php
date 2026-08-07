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
                <select name="division_id" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
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
        <div class="text-right mt-4 pt-4 border-t border-ink/10">
            <span class="text-sm text-mute">সাবটোটাল: </span>
            <span class="font-bold text-lg" id="subtotalShow">0৳</span>
        </div>
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <p class="font-bold text-sm">পেমেন্ট ও চার্জ</p>
        <div class="grid md:grid-cols-3 gap-4">
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
                <label class="text-sm font-medium">ডেলিভারি চার্জ</label>
                <input name="delivery_charge" type="number" step="0.01" min="0" value="0" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            </div>
            <div>
                <label class="text-sm font-medium">ডিসকাউন্ট</label>
                <input name="discount" type="number" step="0.01" min="0" value="0" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            </div>
        </div>
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

    let rowIdx = 0;

    function addRow() {
        document.getElementById('noItemMsg').style.display = 'none';
        const wrap = document.getElementById('itemRows');
        const div = document.createElement('div');
        div.className = 'flex gap-3 items-center';
        div.id = 'row' + rowIdx;

        let productOptions = products.map((p, pi) => `<option value="${pi}">${p.name}</option>`).join('');

        div.innerHTML = `
            <select class="prodSelect flex-1 rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white" onchange="updateVariants(${rowIdx})">
                <option value="">প্রোডাক্ট বাছাই করুন</option>${productOptions}
            </select>
            <select class="variantSelect w-48 rounded-lg border border-ink/15 px-3 py-2 text-sm bg-white" onchange="calcTotal()"></select>
            <input type="number" class="qtyInput w-20 rounded-lg border border-ink/15 px-3 py-2 text-sm" value="1" min="1" onchange="calcTotal()">
            <span class="lineTotal w-24 text-right text-sm font-semibold">0৳</span>
            <button type="button" onclick="removeRow(${rowIdx})" class="text-red-600 text-sm">✕</button>
        `;
        wrap.appendChild(div);
        rowIdx++;
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
</script>
@endpush
@endsection
