@php
    $firstVariant = $product->variants->first();
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
<x-landing.section :global="$global" :design="$data['design'] ?? null" id="checkout-section" class="scroll-mt-4">
    <div class="bg-white rounded-card border border-ink/5 p-5 text-left">
        @if ($data['heading'] ?? null)
            <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-4">{{ $data['heading'] }}</h2>
        @endif

        <form method="POST" action="{{ route('storefront.landing.order', $landingPage->slug) }}" id="landingCheckoutForm" class="space-y-4">
            @csrf
            @include('storefront.partials.product-buy-widget', ['product' => $product])

            <div class="border-t border-ink/10 pt-4 space-y-4">
                <div>
                    <label class="text-sm font-medium">আপনার নাম *</label>
                    <input name="customer_name" value="{{ old('customer_name') }}" required
                           class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-brand outline-none">
                </div>
                <div>
                    <label class="text-sm font-medium">মোবাইল নাম্বার *</label>
                    <input name="customer_phone" value="{{ old('customer_phone') }}" required placeholder="01XXXXXXXXX"
                           class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-brand outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium">বিভাগ *</label>
                        <select name="division_id" id="divisionSelect" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-white">
                            <option value="">— বাছাই করুন —</option>
                            @foreach ($divisions as $d)
                                <option value="{{ $d->id }}" @selected(old('division_id') == $d->id)>{{ $d->bn_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-medium">জেলা *</label>
                        <select name="district_id" id="districtSelect" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-white">
                            <option value="">— আগে বিভাগ দিন —</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium">সম্পূর্ণ ঠিকানা *</label>
                    <textarea name="customer_address" rows="2" required placeholder="বাসা/হোল্ডিং, রোড, এলাকা, থানা"
                        class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-brand outline-none">{{ old('customer_address') }}</textarea>
                </div>

                @if ($errors->any())
                    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">
                        <ul class="list-disc ml-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <div class="text-sm space-y-1.5 border-t border-dashed border-ink/15 pt-3">
                    <div class="flex justify-between"><span class="text-mute">ডেলিভারি চার্জ</span><span id="chargeShow">বিভাগ বাছাই করুন</span></div>
                    <div class="flex justify-between font-bold text-base"><span>মোট</span><span id="totalShow">{{ number_format($firstVariant?->selling_price ?? 0) }}৳</span></div>
                </div>

                <button id="buyBtn" class="w-full text-center {{ $resolver->buttonClasses($global) }} disabled:opacity-50" @disabled(!$firstVariant || $firstVariant->stockCount() <= 0)>
                    🛒 <span id="buyBtnLabel">অর্ডার কনফার্ম করুন (ক্যাশ অন ডেলিভারি)</span>
                </button>
            </div>
        </form>
    </div>
</x-landing.section>

@push('scripts')
<script>
    (function () {
        const districts = @json($districts);
        const dhakaId = {{ $dhakaDivisionId }};
        const chargeInside = {{ $chargeInside }};
        const chargeOutside = {{ $chargeOutside }};

        const divSel = document.getElementById('divisionSelect');
        const disSel = document.getElementById('districtSelect');
        const qtyInput = document.getElementById('qtyInput');

        function currentDeliveryCharge() {
            const divId = parseInt(divSel.value);
            return divId ? (divId === dhakaId ? chargeInside : chargeOutside) : null;
        }

        function recalcTotal() {
            const unitPrice = parseFloat((document.getElementById('priceShow')?.textContent || '0').replace(/[^\d.]/g, '')) || 0;
            const qty = parseInt(qtyInput?.value, 10) || 1;
            const delivery = currentDeliveryCharge();

            document.getElementById('chargeShow').textContent = delivery === null ? 'বিভাগ বাছাই করুন' : delivery.toLocaleString() + '৳';
            document.getElementById('totalShow').textContent = (unitPrice * qty + (delivery || 0)).toLocaleString() + '৳';
        }

        divSel.addEventListener('change', () => {
            const divId = parseInt(divSel.value);
            disSel.innerHTML = '<option value="">— বাছাই করুন —</option>';
            districts.filter(d => d.division_id === divId).forEach(d => {
                disSel.insertAdjacentHTML('beforeend', `<option value="${d.id}">${d.bn_name}</option>`);
            });
            recalcTotal();
        });

        // The variant-picker partial owns priceShow/qtyInput and reacts to
        // its own clicks first (this script renders after it in the
        // page, so listeners attached here run second on the same click/
        // change events) — recalcTotal() here just re-reads the DOM values
        // it already updated, instead of duplicating the price-lookup logic.
        document.querySelectorAll('.axis-btn').forEach(btn => btn.addEventListener('click', recalcTotal));
        document.querySelectorAll('#variantPick input').forEach(r => r.addEventListener('change', recalcTotal));
        document.getElementById('qtyUp')?.addEventListener('click', recalcTotal);
        document.getElementById('qtyDown')?.addEventListener('click', recalcTotal);
        qtyInput?.addEventListener('input', recalcTotal);

        recalcTotal();
    })();
</script>
@endpush
