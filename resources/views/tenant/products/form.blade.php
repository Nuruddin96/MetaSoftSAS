@extends('layouts.panel')

@section('title', $product ? 'প্রোডাক্ট এডিট' : 'নতুন প্রোডাক্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">{{ $product ? 'প্রোডাক্ট এডিট' : 'নতুন প্রোডাক্ট' }}</h1>

<form method="POST" enctype="multipart/form-data"
      action="{{ $product ? route('tenant.products.update', $product) : route('tenant.products.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    @if ($product) @method('PUT') @endif

    <x-ui.card class="space-y-4">
        <div>
            <label class="text-sm font-medium">প্রোডাক্টের নাম *</label>
            <input name="name" value="{{ old('name', $product?->name) }}" required
                   class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">ক্যাটাগরি</label>
                <select name="category_id" class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 bg-white">
                    <option value="">— নেই —</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(old('category_id', $product?->category_id) == $cat->id)>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">ছবি (থাম্বনেইল)</label>
                <input type="file" name="thumbnail" accept="image/*" class="mt-1 w-full text-sm border border-dashed border-ink/25 rounded-btn px-3 py-2.5 cursor-pointer hover:bg-paper transition file:mr-3 file:px-3 file:py-1.5 file:rounded-btn file:border-0 file:bg-ink/5 file:text-xs file:font-semibold file:cursor-pointer">
            </div>
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
        <div class="flex items-center justify-between mb-4">
            <p class="font-bold">ভ্যারিয়েন্ট ও দাম</p>
            <button type="button" onclick="addVariantRow()" class="text-sm text-leaf font-semibold hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">+ ভ্যারিয়েন্ট যোগ</button>
        </div>
        <p class="text-xs text-mute mb-3">একটাই দাম হলে এক রো-ই রাখুন (নাম "Default")। সাইজ/কালার থাকলে প্রতিটার আলাদা রো — প্রতিটার জন্য আলাদা বারকোড অটো তৈরি হবে। অপশন (Size/Color) দিলে কাস্টমার প্রোডাক্ট পেজে আলাদাভাবে বেছে নিতে পারবে।</p>

        <div id="variantRows" class="space-y-4">
            @php
                $existing = $product
                    ? $product->variants->map(fn ($v) => [
                        'id' => $v->id, 'variant_name' => $v->variant_name,
                        'purchase_price' => $v->purchase_price, 'selling_price' => $v->selling_price,
                        'compare_at_price' => $v->compare_at_price, 'stock' => $v->inventory->sum('quantity'),
                        'attr1_name' => array_key_first($v->attributes ?? []) ?? '',
                        'attr1_value' => $v->attributes[array_key_first($v->attributes ?? []) ?? ''] ?? '',
                        'attr2_name' => count($v->attributes ?? []) > 1 ? array_keys($v->attributes)[1] : '',
                        'attr2_value' => count($v->attributes ?? []) > 1 ? array_values($v->attributes)[1] : '',
                    ])->all()
                    : [['id' => null, 'variant_name' => 'Default', 'purchase_price' => '', 'selling_price' => '', 'compare_at_price' => '', 'stock' => '', 'attr1_name' => '', 'attr1_value' => '', 'attr2_name' => '', 'attr2_value' => '']];
                $existing = old('variants', $existing);
            @endphp
            @foreach ($existing as $i => $v)
                <div class="flex items-start gap-2 variant-row border border-ink/10 rounded-lg p-3">
                    <div class="flex-1 min-w-0 space-y-2">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <input type="hidden" name="variants[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}">
                            <input name="variants[{{ $i }}][variant_name]" value="{{ $v['variant_name'] ?? '' }}" placeholder="নাম (লাল / XL)"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                            <input name="variants[{{ $i }}][purchase_price]" value="{{ $v['purchase_price'] ?? '' }}" type="number" step="0.01" min="0" placeholder="কেনা দাম"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                            <input name="variants[{{ $i }}][selling_price]" value="{{ $v['selling_price'] ?? '' }}" type="number" step="0.01" min="0" required placeholder="বিক্রয় দাম (offer) *"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                            @if (!$product)
                                <input name="variants[{{ $i }}][stock]" value="{{ $v['stock'] ?? '' }}" type="number" min="0" placeholder="শুরুর স্টক"
                                       class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                            @else
                                <span class="text-xs text-mute self-center">স্টক: {{ $v['stock'] ?? 0 }} (ইনভেন্টরি পেজে বদলান)</span>
                            @endif
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                            <input name="variants[{{ $i }}][compare_at_price]" value="{{ $v['compare_at_price'] ?? '' }}" type="number" step="0.01" min="0" placeholder="আগের দাম (ঐচ্ছিক, offer থাকলে)"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm md:col-span-2">
                            <input name="variants[{{ $i }}][attr1_name]" value="{{ $v['attr1_name'] ?? '' }}" placeholder="অপশন ১ (যেমন Size)"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                            <input name="variants[{{ $i }}][attr1_value]" value="{{ $v['attr1_value'] ?? '' }}" placeholder="মান (যেমন M)"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-3 md:w-1/2">
                            <input name="variants[{{ $i }}][attr2_name]" value="{{ $v['attr2_name'] ?? '' }}" placeholder="অপশন ২ (যেমন Color)"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                            <input name="variants[{{ $i }}][attr2_value]" value="{{ $v['attr2_value'] ?? '' }}" placeholder="মান (যেমন Blue)"
                                   class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                        </div>
                    </div>
                    @if (!empty($v['id']))
                        {{-- Real deletion (Catalog Architecture project) — this row is
                             an already-saved variant, so just hiding it in the DOM
                             would NOT delete it server-side (ProductController::update()
                             only creates/updates rows present in the submitted array,
                             it never deletes ones missing from it). Submits to the new
                             products.variants.destroy route instead, which the backend
                             also blocks on the product's last remaining variant. --}}
                        <form method="POST" action="{{ route('tenant.products.variants.destroy', [$product, $v['id']]) }}"
                              onsubmit="return confirm('এই ভ্যারিয়েন্ট মুছে ফেলবেন?')" class="shrink-0">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-red-600 text-sm px-2 py-2" aria-label="ভ্যারিয়েন্ট মুছুন">✕</button>
                        </form>
                    @else
                        <button type="button" onclick="removeVariantRow(this)" class="shrink-0 text-red-600 text-sm px-2 py-2" aria-label="ভ্যারিয়েন্ট মুছুন">✕</button>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.button type="submit" variant="accent" size="lg">
        {{ $product ? 'আপডেট করুন' : 'প্রোডাক্ট যোগ করুন' }}
    </x-ui.button>
</form>

@push('scripts')
<script>
    let idx = {{ count($existing) }};
    function addVariantRow() {
        const wrap = document.getElementById('variantRows');
        const div = document.createElement('div');
        div.className = 'flex items-start gap-2 variant-row border border-ink/10 rounded-lg p-3';
        div.innerHTML = `
            <div class="flex-1 min-w-0 space-y-2">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <input type="hidden" name="variants[${idx}][id]" value="">
                    <input name="variants[${idx}][variant_name]" placeholder="নাম (লাল / XL)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${idx}][purchase_price]" type="number" step="0.01" min="0" placeholder="কেনা দাম" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${idx}][selling_price]" type="number" step="0.01" min="0" required placeholder="বিক্রয় দাম (offer) *" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${idx}][stock]" type="number" min="0" placeholder="শুরুর স্টক" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <input name="variants[${idx}][compare_at_price]" type="number" step="0.01" min="0" placeholder="আগের দাম (ঐচ্ছিক, offer থাকলে)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm md:col-span-2">
                    <input name="variants[${idx}][attr1_name]" placeholder="অপশন ১ (যেমন Size)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${idx}][attr1_value]" placeholder="মান (যেমন M)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
                <div class="grid grid-cols-2 gap-3 md:w-1/2">
                    <input name="variants[${idx}][attr2_name]" placeholder="অপশন ২ (যেমন Color)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[${idx}][attr2_value]" placeholder="মান (যেমন Blue)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                </div>
            </div>
            <button type="button" onclick="removeVariantRow(this)" class="shrink-0 text-red-600 text-sm px-2 py-2" aria-label="ভ্যারিয়েন্ট মুছুন">✕</button>`;
        wrap.appendChild(div);
        idx++;
    }

    /** At least one variant row must always remain — a product needs at least one price. */
    function removeVariantRow(button) {
        const rows = document.querySelectorAll('#variantRows .variant-row');
        if (rows.length <= 1) return;
        button.closest('.variant-row').remove();
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

            fetch('{{ $product ? route('tenant.products.images.reorder', $product) : '#' }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: JSON.stringify({ order }),
            }).then(r => r.json()).then(res => {
                status.textContent = res.ok ? 'ক্রম সেভ হয়েছে।' : (res.message || 'সমস্যা হয়েছে, পেজ রিফ্রেশ করুন।');
            }).catch(() => { status.textContent = 'সমস্যা হয়েছে, আবার চেষ্টা করুন।'; });
        }
    }
</script>
@endpush
@endsection
