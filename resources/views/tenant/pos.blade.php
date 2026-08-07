@extends('layouts.panel')

@section('title', 'POS')

@section('content')
<div class="grid lg:grid-cols-3 gap-6">

    {{-- left: scan + product grid --}}
    <div class="lg:col-span-2">
        <div class="flex gap-3 mb-4">
            <input id="scanInput" autofocus autocomplete="off"
                   placeholder="🔍 বারকোড স্ক্যান করুন বা প্রোডাক্ট খুঁজুন..."
                   class="flex-1 rounded-card border-2 border-leaf/40 px-4 py-3 text-lg focus:ring-2 focus:ring-leaf outline-none bg-white">
        </div>
        <p class="text-xs text-mute mb-3">স্ক্যানার দিয়ে বারকোড স্ক্যান করলেই কার্টে যোগ হবে। নিচের গ্রিড থেকেও ক্লিক করা যাবে।</p>

        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3" id="productGrid">
            @foreach ($products as $product)
                @foreach ($product->variants as $v)
                    <button type="button"
                            class="bg-white rounded-card border border-ink/5 p-3 text-left hover:border-leaf/40 hover:shadow-sm transition pos-card pos-add focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2"
                            data-id="{{ $v->id }}"
                            data-pname="{{ $product->name }}"
                            data-variant="{{ $v->variant_name }}"
                            data-price="{{ $v->selling_price }}"
                            data-name="{{ strtolower($product->name . ' ' . $v->variant_name) }}">
                        <p class="text-sm font-medium leading-snug line-clamp-2">{{ $product->name }}</p>
                        @if ($v->variant_name !== 'Default')
                            <p class="text-xs text-mute">{{ $v->variant_name }}</p>
                        @endif
                        <p class="font-bold text-leaf mt-1">{{ number_format($v->selling_price) }}৳</p>
                        <p class="text-[10px] text-mute">স্টক: {{ $v->totalStock() }}</p>
                    </button>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- right: cart --}}
    <div>
        <x-ui.card class="sticky top-4">
            <p class="font-bold mb-3">🧾 বিক্রি</p>
            <div id="cartItems" class="space-y-2 text-sm max-h-64 overflow-auto"></div>
            <p id="emptyMsg" class="text-mute text-sm text-center py-6">কোনো আইটেম নেই</p>

            <div class="border-t border-dashed border-ink/15 mt-3 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span>সাবটোটাল</span><span id="subShow">0৳</span></div>
                <div class="flex items-center justify-between">
                    <span>ডিসকাউন্ট</span>
                    <input id="discount" type="number" min="0" value="0" onchange="renderCart()"
                           class="w-24 rounded-btn border border-ink/15 px-2 py-1 text-right text-sm">
                </div>
                <div class="flex justify-between font-bold text-lg"><span>মোট</span><span id="totalShow">0৳</span></div>
            </div>

            <div class="mt-4 space-y-3">
                <div class="flex gap-2">
                    <label class="flex-1">
                        <input type="radio" name="pm" value="cash" checked class="peer sr-only" onchange="togglePm()">
                        <span class="block text-center py-2 rounded-btn border border-ink/15 text-sm peer-checked:bg-leaf peer-checked:text-white peer-checked:border-leaf cursor-pointer transition">💵 ক্যাশ</span>
                    </label>
                    <label class="flex-1">
                        <input type="radio" name="pm" value="due" class="peer sr-only" onchange="togglePm()">
                        <span class="block text-center py-2 rounded-btn border border-ink/15 text-sm peer-checked:bg-amber peer-checked:border-amber cursor-pointer transition">📒 বাকি</span>
                    </label>
                </div>

                <div id="dueFields" class="hidden space-y-2">
                    <input id="custName" placeholder="কাস্টমারের নাম *" class="w-full rounded-btn border border-ink/15 px-3 py-2 text-sm">
                    <input id="custPhone" placeholder="মোবাইল নাম্বার * (01XXXXXXXXX)" class="w-full rounded-btn border border-ink/15 px-3 py-2 text-sm">
                    <input id="paidAmount" type="number" min="0" placeholder="এখন কত দিলো (0 = পুরোটা বাকি)" class="w-full rounded-btn border border-ink/15 px-3 py-2 text-sm">
                </div>

                <button onclick="sell()" id="sellBtn"
                        class="w-full py-3.5 rounded-btn bg-ink text-white font-bold hover:bg-ink/90 disabled:opacity-40 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                    বিক্রি সম্পন্ন করুন
                </button>
                <p id="posError" class="text-red-600 text-xs hidden"></p>
            </div>
        </x-ui.card>
    </div>
</div>

@push('scripts')
<script>
    const cart = {};

    document.querySelectorAll('.pos-add').forEach(btn =>
        btn.addEventListener('click', () => addItem({
            id: parseInt(btn.dataset.id),
            name: btn.dataset.pname,
            variant: btn.dataset.variant,
            price: parseFloat(btn.dataset.price),
        })));

    function addItem(v) {
        if (cart[v.id]) cart[v.id].qty++;
        else cart[v.id] = { ...v, qty: 1 };
        renderCart();
    }

    function changeQty(id, d) {
        cart[id].qty += d;
        if (cart[id].qty <= 0) delete cart[id];
        renderCart();
    }

    function renderCart() {
        const wrap = document.getElementById('cartItems');
        const ids = Object.keys(cart);
        document.getElementById('emptyMsg').style.display = ids.length ? 'none' : 'block';
        wrap.innerHTML = '';
        let sub = 0;
        ids.forEach(id => {
            const i = cart[id];
            sub += i.price * i.qty;
            wrap.insertAdjacentHTML('beforeend', `
                <div class="flex items-center gap-2">
                    <div class="flex-1 min-w-0">
                        <p class="truncate">${i.name}${i.variant && i.variant !== 'Default' ? ' <span class="text-mute text-xs">(' + i.variant + ')</span>' : ''}</p>
                        <p class="text-xs text-mute">${i.price.toLocaleString()}৳ × ${i.qty}</p>
                    </div>
                    <button onclick="changeQty(${id},-1)" class="w-6 h-6 rounded bg-ink/5">−</button>
                    <span class="w-6 text-center">${i.qty}</span>
                    <button onclick="changeQty(${id},1)" class="w-6 h-6 rounded bg-ink/5">+</button>
                </div>`);
        });
        const disc = Math.min(parseFloat(document.getElementById('discount').value) || 0, sub);
        document.getElementById('subShow').textContent = sub.toLocaleString() + '৳';
        document.getElementById('totalShow').textContent = (sub - disc).toLocaleString() + '৳';
    }

    function togglePm() {
        const due = document.querySelector('[name=pm]:checked').value === 'due';
        document.getElementById('dueFields').classList.toggle('hidden', !due);
    }

    const scanInput = document.getElementById('scanInput');
    scanInput.addEventListener('keydown', async e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const code = scanInput.value.trim();
        if (!code) return;

        const res = await fetch(`{{ route('tenant.pos.scan', ':code') }}`.replace(':code', encodeURIComponent(code)))
            .then(r => r.json()).catch(() => ({ found: false }));

        if (res.found) {
            addItem(res);
            scanInput.value = '';
        }
    });

    scanInput.addEventListener('input', () => {
        const q = scanInput.value.trim().toLowerCase();
        document.querySelectorAll('.pos-card').forEach(c =>
            c.style.display = !q || /^\d+$/.test(q) || c.dataset.name.includes(q) ? '' : 'none');
    });

    async function sell() {
        const ids = Object.keys(cart);
        const err = document.getElementById('posError');
        err.classList.add('hidden');
        if (!ids.length) return;

        document.getElementById('sellBtn').disabled = true;

        const payload = {
            items: ids.map(id => ({ variant_id: parseInt(id), qty: cart[id].qty })),
            discount: parseFloat(document.getElementById('discount').value) || 0,
            payment_method: document.querySelector('[name=pm]:checked').value,
            customer_name: document.getElementById('custName').value,
            customer_phone: document.getElementById('custPhone').value,
            paid_amount: parseFloat(document.getElementById('paidAmount').value) || 0,
        };

        const res = await fetch('{{ route('tenant.pos.sell') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify(payload),
        }).then(r => r.json()).catch(() => null);

        document.getElementById('sellBtn').disabled = false;

        if (res && res.ok) {
            window.open(res.receipt_url, '_blank');
            location.reload();
        } else {
            err.textContent = res?.message || (res?.errors ? Object.values(res.errors).flat().join(' ') : 'সমস্যা হয়েছে, আবার চেষ্টা করুন।');
            err.classList.remove('hidden');
        }
    }
</script>
@endpush
@endsection
