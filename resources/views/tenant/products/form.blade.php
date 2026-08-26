@extends('layouts.panel')

@section('title', $product ? 'প্রোডাক্ট এডিট' : 'নতুন প্রোডাক্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">{{ $product ? 'প্রোডাক্ট এডিট' : 'নতুন প্রোডাক্ট' }}</h1>

<form method="POST" enctype="multipart/form-data" id="productForm"
      action="{{ $product ? route('tenant.products.update', $product) : route('tenant.products.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    @if ($product) @method('PUT') @endif

    <x-ui.card class="space-y-4">
        <p class="font-bold">ক্যাটাগরি</p>
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">ক্যাটাগরি</label>
                <select id="categorySelect" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                    <option value="">— নেই —</option>
                    @foreach ($categories->whereNull('parent_id') as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">সাব-ক্যাটাগরি (ঐচ্ছিক)</label>
                <select id="subcategorySelect" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                    <option value="">— নেই —</option>
                </select>
            </div>
        </div>
        <input type="hidden" name="category_id" id="categoryIdInput" value="{{ old('category_id', $product?->category_id) }}">
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <div>
            <label class="text-sm font-medium">প্রোডাক্টের নাম *</label>
            <input name="name" value="{{ old('name', $product?->name) }}" required
                   class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
        <div>
            <label class="text-sm font-medium">ছবি (থাম্বনেইল)</label>
            <input type="file" name="thumbnail" accept="image/*" class="mt-1 w-full text-sm border border-dashed border-ink/25 rounded-btn px-3 py-2.5 cursor-pointer hover:bg-paper transition file:mr-3 file:px-3 file:py-1.5 file:rounded-btn file:border-0 file:bg-ink/5 file:text-xs file:font-semibold file:cursor-pointer">
        </div>
        <div>
            <label class="text-sm font-medium">বর্ণনা</label>
            <textarea name="description" rows="3"
                class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">{{ old('description', $product?->description) }}</textarea>
        </div>
        @if ($product)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($product->is_active)> অ্যাক্টিভ (শপে দেখাবে)
            </label>
        @endif
    </x-ui.card>

    <x-ui.card class="space-y-4">
        <p class="font-bold">গ্যালারি ছবি (একাধিক)</p>
        <p class="text-xs text-mute">থাম্বনেইল (উপরের "ছবি" ফিল্ড) হলো ফিচার্ড ছবি — এখানে প্রোডাক্টের আরও ছবি যোগ করুন। সর্বোচ্চ ৮টি, প্রতিটি সর্বোচ্চ ৪MB।</p>

        @if ($product && $product->images->isNotEmpty())
            <div id="galleryGrid" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-3">
                @foreach ($product->images as $image)
                    <div class="relative group border border-ink/10 rounded-lg overflow-hidden cursor-move" draggable="true" data-id="{{ $image->id }}">
                        <img src="{{ asset('storage/'.$image->image_path) }}" class="w-full aspect-square object-cover pointer-events-none">
                        <form method="POST" action="{{ route('tenant.products.images.destroy', $image) }}"
                              onsubmit="return confirm('ছবিটি মুছে ফেলবেন?')" class="absolute top-1 right-1">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-6 h-6 rounded-full bg-black/60 text-white text-xs grid place-items-center hover:bg-red-600">×</button>
                        </form>
                    </div>
                @endforeach
            </div>
            <p id="reorderStatus" class="text-xs text-mute h-4"></p>
            <p class="text-xs text-mute">ছবি টেনে (drag) ক্রম বদলাতে পারবেন — ক্রম বদলালে অটো সেভ হয়ে যাবে।</p>
        @endif

        <div>
            <label class="text-sm font-medium">নতুন গ্যালারি ছবি যোগ করুন</label>
            <input type="file" name="gallery[]" multiple accept="image/*" class="mt-1 w-full text-sm border border-dashed border-ink/25 rounded-btn px-3 py-2.5 cursor-pointer hover:bg-paper transition file:mr-3 file:px-3 file:py-1.5 file:rounded-btn file:border-0 file:bg-ink/5 file:text-xs file:font-semibold file:cursor-pointer">
        </div>
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-1">অ্যাট্রিবিউট থেকে ভ্যারিয়েন্ট তৈরি করুন (ঐচ্ছিক)</p>
        <p class="text-xs text-mute mb-3">রঙ/সাইজ ইত্যাদি বেছে নিয়ে "ভ্যারিয়েন্ট তৈরি করুন" চাপুন — সবগুলো কম্বিনেশন অটো তৈরি হবে। কিছু না বাছলে প্রোডাক্টটি সাধারণ (এক দামের) থেকে যাবে।</p>

        @if ($attributes->isEmpty())
            <p class="text-xs text-mute">কোনো অ্যাট্রিবিউট নেই — <a href="{{ route('tenant.attributes.index') }}" class="text-leafdk underline">এখানে</a> রঙ/সাইজ ইত্যাদি তৈরি করুন।</p>
        @else
            <div id="attributePicker" class="space-y-3"></div>
            <button type="button" id="generateBtn" class="mt-3 rounded-btn border border-leaf text-leafdk px-4 py-2 text-sm font-semibold hover:bg-leaf/5">
                ✨ ভ্যারিয়েন্ট তৈরি করুন
            </button>
        @endif
    </x-ui.card>

    <x-ui.card>
        <p class="font-bold mb-3">ভ্যারিয়েন্ট ও দাম</p>
        <div id="variantRows" class="space-y-4"></div>
    </x-ui.card>

    <x-ui.button type="submit" variant="accent" size="lg">
        {{ $product ? 'আপডেট করুন' : 'প্রোডাক্ট যোগ করুন' }}
    </x-ui.button>
</form>

{{--
    Every dynamic string a route()/csrf_token() call produces is read from
    these data-* attributes, never echoed directly inside the <script>
    block below — Blade's echo-tag compiler scans the WHOLE file for
    matching braces/parens, and nested route(...) calls sitting among a
    script full of raw JS object literals ({}, ${...}) confuse that scan
    (confirmed: moving them out here is what fixes the parse error).
--}}
<div id="productFormConfig" class="hidden"
     data-csrf="{{ csrf_token() }}"
     data-reorder-url="{{ $product ? route('tenant.products.images.reorder', $product) : '' }}"
     data-delete-variant-url-template="{{ $product ? route('tenant.products.variants.destroy', [$product, 0]) : '' }}"
></div>

@php
    // Precomputed as a plain variable (not an inline closure inside
    // @json(...) in the <script> block) for the same reason as the
    // data-* attributes above — kept the parser confused otherwise.
    $categoriesForJs = $categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id]);
    $attributesForJs = $attributes->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'values' => $a->values->pluck('value')]);
    $existingVariantsForJs = $product ? $product->variants->map(fn ($v) => [
        'id' => $v->id, 'name' => $v->variant_name, 'selling_price' => $v->selling_price,
        'purchase_price' => $v->purchase_price, 'compare_at_price' => $v->compare_at_price,
        'sku' => $v->sku, 'stock' => $v->inventory->sum('quantity'), 'attributes' => $v->attributes,
    ]) : collect();
@endphp

@push('scripts')
<script>
    const CATEGORIES = @json($categoriesForJs);
    const ATTRIBUTES = @json($attributesForJs);
    const EXISTING_VARIANTS = @json($existingVariantsForJs);
    const IS_EDIT = {{ $product ? 'true' : 'false' }};
    const CONFIG = document.getElementById('productFormConfig').dataset;
    // Route::helper-generated (not hand-assembled) so this keeps working
    // regardless of tenancy mode (path vs subdomain — see CLAUDE.md); '0'
    // is swapped for the real variant id per row at render time.
    const DELETE_VARIANT_URL_TEMPLATE = CONFIG.deleteVariantUrlTemplate;
    const CSRF_TOKEN = CONFIG.csrf;
    const SELECTED_CATEGORY_ID = {{ old('category_id', $product?->category_id) ?? 'null' }};

    // --- Category → Subcategory cascading ---
    const categorySelect = document.getElementById('categorySelect');
    const subcategorySelect = document.getElementById('subcategorySelect');
    const categoryIdInput = document.getElementById('categoryIdInput');

    function childrenOf(parentId) {
        return CATEGORIES.filter(c => c.parent_id === parentId);
    }

    function renderSubcategories(parentId, selectedId) {
        const kids = childrenOf(parentId);
        subcategorySelect.innerHTML = '<option value="">— নেই —</option>' +
            kids.map(c => `<option value="${c.id}" ${c.id === selectedId ? 'selected' : ''}>${c.name}</option>`).join('');
        subcategorySelect.disabled = kids.length === 0;
    }

    function updateCategoryIdInput() {
        categoryIdInput.value = subcategorySelect.value || categorySelect.value || '';
    }

    categorySelect.addEventListener('change', () => {
        renderSubcategories(parseInt(categorySelect.value, 10) || null, null);
        updateCategoryIdInput();
    });
    subcategorySelect.addEventListener('change', updateCategoryIdInput);

    // Restore selection on edit/validation-error redisplay: if the saved
    // category_id is itself a subcategory, select its parent first so the
    // subcategory list populates, then select the subcategory.
    (function initCategorySelection() {
        if (!SELECTED_CATEGORY_ID) return;
        const match = CATEGORIES.find(c => c.id === SELECTED_CATEGORY_ID);
        if (!match) return;
        if (match.parent_id) {
            categorySelect.value = match.parent_id;
            renderSubcategories(match.parent_id, SELECTED_CATEGORY_ID);
        } else {
            categorySelect.value = SELECTED_CATEGORY_ID;
            renderSubcategories(SELECTED_CATEGORY_ID, null);
        }
        updateCategoryIdInput();
    })();

    // --- Attribute/value picker ---
    const selectedValues = {}; // attributeName -> Set of values

    function renderAttributePicker() {
        const wrap = document.getElementById('attributePicker');
        if (!wrap) return;
        wrap.innerHTML = ATTRIBUTES.map(attr => `
            <div>
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" class="attr-toggle" data-name="${attr.name}"> ${attr.name}
                </label>
                <div class="flex flex-wrap gap-2 mt-2 ml-6 values-wrap" data-name="${attr.name}" style="display:none">
                    ${attr.values.map(v => `
                        <label class="inline-flex items-center gap-1 text-xs rounded-pill border border-ink/15 px-2.5 py-1 cursor-pointer">
                            <input type="checkbox" class="value-toggle" data-name="${attr.name}" data-value="${v}"> ${v}
                        </label>
                    `).join('')}
                    ${attr.values.length === 0 ? '<span class="text-xs text-mute">কোনো ভ্যালু নেই</span>' : ''}
                </div>
            </div>
        `).join('');

        wrap.querySelectorAll('.attr-toggle').forEach(el => el.addEventListener('change', () => {
            const valuesWrap = wrap.querySelector(`.values-wrap[data-name="${el.dataset.name}"]`);
            valuesWrap.style.display = el.checked ? 'flex' : 'none';
            if (!el.checked) {
                delete selectedValues[el.dataset.name];
                valuesWrap.querySelectorAll('.value-toggle').forEach(v => { v.checked = false; });
            }
        }));
        wrap.querySelectorAll('.value-toggle').forEach(el => el.addEventListener('change', () => {
            const set = selectedValues[el.dataset.name] || (selectedValues[el.dataset.name] = new Set());
            el.checked ? set.add(el.dataset.value) : set.delete(el.dataset.value);
        }));
    }
    renderAttributePicker();

    /** Pure cartesian-product generator — mirrors the Flutter app's generateVariantCombinations(). */
    function generateCombinations(valuesByName) {
        const relevant = Object.entries(valuesByName).filter(([, values]) => values.size > 0);
        if (relevant.length === 0) return [];
        let combos = [{}];
        for (const [name, values] of relevant) {
            const next = [];
            for (const combo of combos) {
                for (const value of values) next.push({ ...combo, [name]: value });
            }
            combos = next;
        }
        return combos;
    }

    function sameCombo(a, b) {
        const aKeys = Object.keys(a || {}), bKeys = Object.keys(b || {});
        if (aKeys.length !== bKeys.length) return false;
        return aKeys.every(k => (a || {})[k] === (b || {})[k]);
    }

    // --- Variant rows: existing (server-known, real-delete) + generated (in-memory, removable) ---
    const rowsWrap = document.getElementById('variantRows');
    let rowIdx = 0;
    let hasAnyRow = false;

    function rowHtml(row) {
        const i = rowIdx++;
        hasAnyRow = true;
        const comboLabel = row.attributes && Object.keys(row.attributes).length
            ? Object.values(row.attributes).join(' / ')
            : (row.name || 'Default');
        const attrInputs = row.attributes
            ? Object.entries(row.attributes).map(([k, v]) => `<input type="hidden" name="variants[${i}][attributes][${k}]" value="${v}">`).join('')
            : '';
        const stockField = (!IS_EDIT)
            ? `<input name="variants[${i}][stock]" value="${row.stock ?? ''}" type="number" min="0" placeholder="শুরুর স্টক" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">`
            : `<span class="text-xs text-mute self-center">স্টক: ${row.stock ?? 0} (ইনভেন্টরি পেজে বদলান)</span>`;

        const deleteControl = row.id
            ? `<form method="POST" action="${DELETE_VARIANT_URL_TEMPLATE.replace(/\/0$/, '/' + row.id)}"
                     onsubmit="return confirm('এই ভ্যারিয়েন্ট মুছে ফেলবেন?')" class="shrink-0">
                 <input type="hidden" name="_token" value="${CSRF_TOKEN}">
                 <input type="hidden" name="_method" value="DELETE">
                 <button type="submit" class="text-red-600 text-sm px-2 py-2" aria-label="ভ্যারিয়েন্ট মুছুন">✕</button>
               </form>`
            : `<button type="button" onclick="this.closest('.variant-row').remove()" class="shrink-0 text-red-600 text-sm px-2 py-2" aria-label="ভ্যারিয়েন্ট মুছুন">✕</button>`;

        const div = document.createElement('div');
        div.className = 'flex items-start gap-2 variant-row border border-ink/10 rounded-lg p-3';
        div.innerHTML = `
            <div class="flex-1 min-w-0 space-y-2">
                <p class="text-sm font-semibold">${i + 1}. ${comboLabel}</p>
                <input type="hidden" name="variants[${i}][id]" value="${row.id ?? ''}">
                <input type="hidden" name="variants[${i}][variant_name]" value="${comboLabel}">
                ${attrInputs}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <input name="variants[${i}][purchase_price]" value="${row.purchase_price ?? ''}" type="number" step="0.01" min="0" placeholder="কেনা দাম" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${i}][selling_price]" value="${row.selling_price ?? ''}" type="number" step="0.01" min="0" required placeholder="বিক্রয় দাম *" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${i}][compare_at_price]" value="${row.compare_at_price ?? ''}" type="number" step="0.01" min="0" placeholder="আগের দাম (ঐচ্ছিক)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${i}][sku]" value="${row.sku ?? ''}" placeholder="SKU (ঐচ্ছিক)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
                ${stockField}
            </div>
            ${deleteControl}`;
        return div;
    }

    function addRow(row) {
        rowsWrap.appendChild(rowHtml(row));
    }

    // Seed with existing variants (edit mode) or one blank default row (create mode).
    if (EXISTING_VARIANTS.length > 0) {
        EXISTING_VARIANTS.forEach(addRow);
    } else {
        addRow({ id: null, name: 'Default', selling_price: '', attributes: null });
    }

    const generateBtn = document.getElementById('generateBtn');
    if (generateBtn) {
        generateBtn.addEventListener('click', () => {
            const combos = generateCombinations(selectedValues);
            if (combos.length === 0) return;

            const currentCombos = Array.from(rowsWrap.querySelectorAll('.variant-row')).map(rowEl => {
                const attrs = {};
                rowEl.querySelectorAll('input[type=hidden][name*="[attributes]"]').forEach(inp => {
                    const m = inp.name.match(/\[attributes\]\[(.+)\]$/);
                    if (m) attrs[m[1]] = inp.value;
                });
                return attrs;
            });

            // The very first render only ever has one blank "Default" row
            // with no attributes at all — once real attribute combinations
            // exist, that placeholder row no longer makes sense and is
            // replaced rather than kept alongside real combinations.
            if (rowsWrap.children.length === 1 && Object.keys(currentCombos[0] || {}).length === 0
                && !rowsWrap.children[0].querySelector('input[name*="[id]"]').value) {
                rowsWrap.innerHTML = '';
            }

            let added = 0;
            combos.forEach(combo => {
                const isDuplicate = Array.from(rowsWrap.querySelectorAll('.variant-row')).some(rowEl => {
                    const attrs = {};
                    rowEl.querySelectorAll('input[type=hidden][name*="[attributes]"]').forEach(inp => {
                        const m = inp.name.match(/\[attributes\]\[(.+)\]$/);
                        if (m) attrs[m[1]] = inp.value;
                    });
                    return sameCombo(attrs, combo);
                });
                if (!isDuplicate) {
                    addRow({ id: null, attributes: combo, selling_price: '' });
                    added++;
                }
            });

            if (added === 0) {
                alert('বাছাই করা সবগুলো কম্বিনেশন ইতিমধ্যে তালিকায় আছে।');
            }
        });
    }

    const galleryGrid = document.getElementById('galleryGrid');
    if (galleryGrid) {
        let dragEl = null;

        galleryGrid.querySelectorAll('[draggable="true"]').forEach(el => {
            el.addEventListener('dragstart', () => { dragEl = el; el.classList.add('opacity-40'); });
            el.addEventListener('dragend', () => el.classList.remove('opacity-40'));
            el.addEventListener('dragover', (e) => e.preventDefault());
            el.addEventListener('drop', (e) => {
                e.preventDefault();
                if (!dragEl || dragEl === el) return;

                const items = Array.from(galleryGrid.children);
                if (items.indexOf(dragEl) < items.indexOf(el)) el.after(dragEl); else el.before(dragEl);

                saveGalleryOrder();
            });
        });

        function saveGalleryOrder() {
            const order = Array.from(galleryGrid.children).map(el => parseInt(el.dataset.id, 10));
            const status = document.getElementById('reorderStatus');
            status.textContent = 'সেভ হচ্ছে...';

            fetch(CONFIG.reorderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json' },
                body: JSON.stringify({ order }),
            }).then(r => r.json()).then(res => {
                status.textContent = res.ok ? 'ক্রম সেভ হয়েছে।' : (res.message || 'সমস্যা হয়েছে, পেজ রিফ্রেশ করুন।');
            }).catch(() => { status.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।'; });
        }
    }
</script>
@endpush
@endsection
