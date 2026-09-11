@extends('layouts.store')

@section('title', 'চেকআউট — ' . $tenant->store_name)

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">অর্ডার সম্পন্ন করুন</h1>

<div class="grid md:grid-cols-5 gap-8">
    <form method="POST" action="{{ route('storefront.checkout.place') }}" class="md:col-span-3 space-y-5" id="checkoutForm">
        @csrf

        {{-- Presented as numbered steps for clarity — this is still one page,
             one form, one POST to storefront.checkout.place, so none of the
             division→district cascading or incomplete-order tracking JS
             below needed to change. --}}
        <div class="bg-white rounded-card border border-ink/5 p-4 sm:p-5">
            <p class="flex items-center gap-2 font-semibold text-sm mb-4">
                <span class="w-6 h-6 rounded-full bg-brand text-white text-xs grid place-items-center shrink-0">1</span> গ্রাহকের তথ্য
            </p>
            <div class="space-y-4">
                <div>
                    <label class="text-sm font-medium">আপনার নাম *</label>
                    <input name="customer_name" value="{{ old('customer_name') }}" required
                           class="mt-1 w-full h-11 rounded-btn border border-ink/15 px-3 focus:ring-1 focus:ring-brand focus:border-brand outline-none">
                </div>
                <div>
                    <label class="text-sm font-medium">মোবাইল নাম্বার *</label>
                    <input name="customer_phone" value="{{ old('customer_phone') }}" required placeholder="01XXXXXXXXX"
                           class="mt-1 w-full h-11 rounded-btn border border-ink/15 px-3 focus:ring-1 focus:ring-brand focus:border-brand outline-none">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-card border border-ink/5 p-4 sm:p-5">
            <p class="flex items-center gap-2 font-semibold text-sm mb-4">
                <span class="w-6 h-6 rounded-full bg-brand text-white text-xs grid place-items-center shrink-0">2</span> ডেলিভারি তথ্য
            </p>
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-sm font-medium">বিভাগ *</label>
                        <select name="division_id" id="divisionSelect" required class="mt-1 w-full h-11 rounded-btn border border-ink/15 px-3 bg-white">
                            <option value="">— বাছাই করুন —</option>
                            @foreach ($divisions as $d)
                                <option value="{{ $d->id }}" @selected(old('division_id') == $d->id)>{{ $d->bn_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium">জেলা *</label>
                        <select name="district_id" id="districtSelect" required class="mt-1 w-full h-11 rounded-btn border border-ink/15 px-3 bg-white">
                            <option value="">— আগে বিভাগ দিন —</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium">সম্পূর্ণ ঠিকানা *</label>
                    <textarea name="customer_address" rows="2" required placeholder="বাসা/হোল্ডিং, রোড, এলাকা, থানা"
                        class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-1 focus:ring-brand focus:border-brand outline-none">{{ old('customer_address') }}</textarea>
                </div>
                <div>
                    <label class="text-sm font-medium">নোট (ঐচ্ছিক)</label>
                    <input name="note" value="{{ old('note') }}" class="mt-1 w-full h-11 rounded-btn border border-ink/15 px-3 outline-none">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-card border border-ink/5 p-4 sm:p-5">
            <p class="flex items-center gap-2 font-semibold text-sm mb-4">
                <span class="w-6 h-6 rounded-full bg-brand text-white text-xs grid place-items-center shrink-0">3</span> পেমেন্ট পদ্ধতি
            </p>
            {{-- Only real, working method — no online-gateway options are
                 shown since none exists for storefront checkout (see
                 CLAUDE.md: Ssl Commerz/bKash there are platform billing
                 only, not customer-facing). A locked, pre-selected radio
                 keeps this an honest step rather than a fake choice. --}}
            <label class="flex items-center gap-3 p-3 rounded-btn border border-brand bg-brand/5 cursor-default">
                <input type="radio" checked disabled class="accent-brand">
                <span class="flex-1">
                    <span class="block text-sm font-medium">ক্যাশ অন ডেলিভারি (COD)</span>
                    <span class="block text-xs text-mute">পণ্য হাতে পেয়ে টাকা পরিশোধ করুন</span>
                </span>
                <i data-lucide="banknote" class="w-5 h-5 text-brand shrink-0"></i>
            </label>
        </div>

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-btn p-3">
                <ul class="list-disc ml-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <button class="w-full h-14 rounded-btn bg-brand text-white font-bold text-base hover:opacity-90">
            অর্ডার কনফার্ম করুন (ক্যাশ অন ডেলিভারি)
        </button>
    </form>

    <div class="md:col-span-2">
        <div class="bg-white rounded-card border border-ink/5 p-5 sticky top-20">
            <p class="font-bold text-sm mb-3">অর্ডার সামারি</p>
            <div class="space-y-2 text-sm">
                @foreach ($items as $item)
                    <div class="flex justify-between gap-2">
                        <span class="text-mute">{{ $item['variant']->product->name }}
                            @if ($item['variant']->variant_name !== 'Default')({{ $item['variant']->variant_name }})@endif
                            × {{ $item['qty'] }}</span>
                        <span class="shrink-0">{{ number_format($item['total']) }}৳</span>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-dashed border-ink/15 mt-3 pt-3 text-sm space-y-1.5">
                <div class="flex justify-between"><span class="text-mute">সাবটোটাল</span><span>{{ number_format($subtotal) }}৳</span></div>
                <div class="flex justify-between"><span class="text-mute">ডেলিভারি চার্জ</span><span id="chargeShow">বিভাগ বাছাই করুন</span></div>
                <div class="flex justify-between font-bold text-base pt-1 border-t border-dashed border-ink/15 mt-1"><span>সর্বমোট</span><span id="totalShow" class="text-brand">{{ number_format($subtotal) }}৳</span></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    if (typeof fbq === 'function') {
        fbq('track', 'InitiateCheckout', { currency: 'BDT', value: {{ $subtotal }} });
    }

    const districts = @json($districts);
    const dhakaId = {{ $divisions->firstWhere('name', 'Dhaka')?->id ?? 0 }};
    const chargeInside = {{ $chargeInside }};
    const chargeOutside = {{ $chargeOutside }};
    const subtotal = {{ $subtotal }};

    const divSel = document.getElementById('divisionSelect');
    const disSel = document.getElementById('districtSelect');

    divSel.addEventListener('change', () => {
        const divId = parseInt(divSel.value);
        disSel.innerHTML = '<option value="">— বাছাই করুন —</option>';
        districts.filter(d => d.division_id === divId).forEach(d => {
            disSel.insertAdjacentHTML('beforeend', `<option value="${d.id}">${d.bn_name}</option>`);
        });
        const charge = divId === dhakaId ? chargeInside : chargeOutside;
        document.getElementById('chargeShow').textContent = charge.toLocaleString() + '৳';
        document.getElementById('totalShow').textContent = (subtotal + charge).toLocaleString() + '৳';
    });

    // Incomplete order tracking: save after phone number typed
    let t;
    document.querySelector('[name=customer_phone]').addEventListener('input', e => {
        clearTimeout(t);
        if (e.target.value.length >= 11) {
            t = setTimeout(() => {
                fetch('{{ route('storefront.checkout.track') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify({
                        customer_name:  document.querySelector('[name=customer_name]').value,
                        customer_phone: e.target.value,
                        customer_address: document.querySelector('[name=customer_address]').value,
                    }),
                }).catch(() => {});
            }, 800);
        }
    });
</script>
@endpush
@endsection
