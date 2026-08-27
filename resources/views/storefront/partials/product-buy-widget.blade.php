{{--
    Variant/attribute picker + price/stock/qty widget. Extracted from the
    product detail page so the single-product landing page checkout section
    (Phase 2) can show the exact same attribute selection logic the spec
    requires, driven by the same DOM ids (variantIdInput, priceShow,
    compareShow, savingsShow, stockShow, buyBtn, buyBtnLabel) that every
    including page is expected to define around it (form + submit button).
--}}
@php
    $axes = $product->optionAxes();
    $firstVariant = $product->variants->first();
    $savings = $firstVariant?->savingsAmount();
    $stock = $firstVariant?->stockCount();
    $outOfStock = $firstVariant && $stock !== null && $stock <= 0;

    $variantMap = $product->variants->mapWithKeys(fn ($v) => [
        collect($axes)->map(fn ($axis) => $v->attributes[$axis] ?? '')->implode('||') => [
            'id' => $v->id,
            'price' => number_format($v->selling_price),
            'compare' => $v->compare_at_price ? number_format($v->compare_at_price) : null,
            'savings' => $v->savingsAmount() ? number_format($v->savingsAmount()) : null,
            'stock' => $v->stockCount(),
            'threshold' => $v->low_stock_threshold,
        ],
    ]);
    $axisValues = collect($axes)->mapWithKeys(fn ($axis) => [
        $axis => $product->variants->pluck("attributes.$axis")->filter()->unique()->values(),
    ]);
@endphp

<input type="hidden" name="variant_id" id="variantIdInput" value="{{ $firstVariant?->id }}">

@if ($axes || $product->variants->count() > 1)
    <div class="space-y-4 pb-4 border-b border-ink/10">
        @if ($axes)
            @foreach ($axes as $axis)
                <div>
                    <label class="text-sm font-medium">{{ \Illuminate\Support\Str::title($axis) }} বাছাই করুন</label>
                    <div class="mt-2 flex flex-wrap gap-2 axis-group" data-axis="{{ $axis }}">
                        @foreach ($axisValues[$axis] as $i => $value)
                            <button type="button"
                                    class="axis-btn px-4 py-2 rounded-lg border border-ink/15 text-sm hover:border-brand {{ $i === 0 ? 'is-selected border-brand bg-brand/10 font-semibold' : '' }}"
                                    data-axis="{{ $axis }}" data-value="{{ $value }}">{{ $value }}</button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @else
            <div>
                <label class="text-sm font-medium">ভ্যারিয়েন্ট বাছাই করুন</label>
                <div class="mt-2 flex flex-wrap gap-2" id="variantPick">
                    @foreach ($product->variants as $i => $v)
                        <label class="cursor-pointer">
                            <input type="radio" name="variant_id_flat" value="{{ $v->id }}" class="peer sr-only"
                                   data-price="{{ number_format($v->selling_price) }}"
                                   data-compare="{{ $v->compare_at_price ? number_format($v->compare_at_price) : '' }}"
                                   data-savings="{{ $v->savingsAmount() ? number_format($v->savingsAmount()) : '' }}"
                                   data-stock="{{ $v->stockCount() }}"
                                   data-threshold="{{ $v->low_stock_threshold }}"
                                   @checked($i === 0)>
                            <span class="inline-block px-4 py-2 rounded-lg border border-ink/15 text-sm peer-checked:border-brand peer-checked:bg-brand/10 peer-checked:font-semibold">
                                {{ $v->variant_name }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
@endif

<div class="flex items-baseline gap-2 flex-wrap">
    <p class="font-disp font-extrabold text-3xl text-brand" id="priceShow">{{ number_format($firstVariant?->selling_price ?? 0) }}৳</p>
    <p class="text-base text-red-400 line-through {{ $savings ? '' : 'hidden' }}" id="compareShow">{{ $firstVariant?->compare_at_price ? number_format($firstVariant->compare_at_price) : '' }}৳</p>
</div>
<p class="text-sm text-red-500 font-medium {{ $savings ? '' : 'hidden' }}" id="savingsShow">Save {{ $savings ? number_format($savings) : '' }} Tk</p>

<p class="text-sm {{ $outOfStock ? 'text-red-600 font-semibold' : ($stock !== null && $stock <= ($firstVariant?->low_stock_threshold ?? 5) ? 'text-amber-600' : 'text-mute') }}" id="stockShow">
    @if ($outOfStock) স্টক শেষ
    @elseif ($stock !== null && $stock <= ($firstVariant?->low_stock_threshold ?? 5)) মাত্র {{ $stock }} টি বাকি
    @else ✓ স্টকে আছে
    @endif
</p>

<div class="flex items-center gap-3">
    <label class="text-sm">পরিমাণ</label>
    <div class="inline-flex items-center border border-ink/15 rounded-lg overflow-hidden">
        <button type="button" id="qtyDown" class="w-9 h-9 grid place-items-center text-lg text-ink/60 hover:bg-ink/5" aria-label="কমান">−</button>
        <input name="qty" id="qtyInput" value="1" type="number" min="1" max="100"
               class="w-12 h-9 text-center border-x border-ink/15 outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none">
        <button type="button" id="qtyUp" class="w-9 h-9 grid place-items-center text-lg text-ink/60 hover:bg-ink/5" aria-label="বাড়ান">+</button>
    </div>
</div>

@push('scripts')
<script>
    const variantMap = @json($variantMap);
    const axes = @json($axes);
    const selected = {};
    axes.forEach(axis => {
        const firstBtn = document.querySelector(`.axis-btn[data-axis="${axis}"]`);
        if (firstBtn) selected[axis] = firstBtn.dataset.value;
    });

    function applyVariantData(d) {
        document.getElementById('variantIdInput').value = d.id;
        document.getElementById('priceShow').textContent = d.price + '৳';

        const compareEl = document.getElementById('compareShow');
        const savingsEl = document.getElementById('savingsShow');
        if (d.savings) {
            compareEl.textContent = d.compare + '৳';
            compareEl.classList.remove('hidden');
            savingsEl.textContent = 'Save ' + d.savings + ' Tk';
            savingsEl.classList.remove('hidden');
        } else {
            compareEl.classList.add('hidden');
            savingsEl.classList.add('hidden');
        }

        const stock = parseInt(d.stock, 10);
        const threshold = parseInt(d.threshold, 10) || 5;
        const stockEl = document.getElementById('stockShow');
        const outOfStock = stock <= 0;

        if (outOfStock) {
            stockEl.textContent = 'স্টক শেষ';
            stockEl.className = 'text-sm text-red-600 font-semibold';
        } else if (stock <= threshold) {
            stockEl.textContent = 'মাত্র ' + stock + ' টি বাকি';
            stockEl.className = 'text-sm text-amber-600';
        } else {
            stockEl.textContent = '✓ স্টকে আছে';
            stockEl.className = 'text-sm text-mute';
        }

        const btn = document.getElementById('buyBtn');
        const label = document.getElementById('buyBtnLabel');
        btn.disabled = outOfStock;
        label.textContent = outOfStock ? 'স্টক নেই' : 'অর্ডার করুন';
    }

    function applyUnavailable() {
        document.getElementById('variantIdInput').value = '';
        document.getElementById('priceShow').textContent = '—';
        document.getElementById('compareShow').classList.add('hidden');
        document.getElementById('savingsShow').classList.add('hidden');

        const stockEl = document.getElementById('stockShow');
        stockEl.textContent = 'এই কম্বিনেশনটি এভেইলেবল নেই';
        stockEl.className = 'text-sm text-red-600 font-semibold';

        const btn = document.getElementById('buyBtn');
        const label = document.getElementById('buyBtnLabel');
        btn.disabled = true;
        label.textContent = 'এভেইলেবল নেই';
    }

    // A combination the customer can pick from the axis buttons may not
    // correspond to any real variant row (sparse matrix — e.g. Black+50ML
    // and White+100ML exist but Black+100ML was never created). Falling
    // through here would leave the previously selected variant's id in the
    // hidden input, letting the customer submit the wrong variant.
    function updateFromAxes() {
        const key = axes.map(a => selected[a] ?? '').join('||');
        const d = variantMap[key];
        if (d) applyVariantData(d); else applyUnavailable();
    }

    document.querySelectorAll('.axis-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const axis = btn.dataset.axis;
            document.querySelectorAll(`.axis-btn[data-axis="${axis}"]`).forEach(b =>
                b.classList.remove('is-selected', 'border-brand', 'bg-brand/10', 'font-semibold'));
            btn.classList.add('is-selected', 'border-brand', 'bg-brand/10', 'font-semibold');
            selected[axis] = btn.dataset.value;
            updateFromAxes();
        });
    });

    // Flat (single-axis-free-text) variant picker — pre-existing behavior, unchanged.
    document.querySelectorAll('#variantPick input').forEach(r =>
        r.addEventListener('change', e => {
            const d = e.target.dataset;
            applyVariantData({
                id: e.target.value, price: d.price, compare: d.compare, savings: d.savings,
                stock: d.stock, threshold: d.threshold,
            });
        }));

    const qtyInput = document.getElementById('qtyInput');
    document.getElementById('qtyDown')?.addEventListener('click', () => {
        qtyInput.value = Math.max(1, (parseInt(qtyInput.value, 10) || 1) - 1);
    });
    document.getElementById('qtyUp')?.addEventListener('click', () => {
        qtyInput.value = Math.min(100, (parseInt(qtyInput.value, 10) || 1) + 1);
    });
</script>
@endpush
