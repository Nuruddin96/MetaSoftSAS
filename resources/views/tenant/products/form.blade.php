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
                <input type="file" name="thumbnail" accept="image/*" class="mt-1 w-full text-sm">
            </div>
        </div>
        <div>
            <label class="text-sm font-medium">বর্ণনা</label>
            <textarea name="description" rows="3"
                class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">{{ old('description', $product?->description) }}</textarea>
        </div>
        @if ($product)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($product->is_active)> অ্যাক্টিভ (দোকানে দেখাবে)
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
            <input type="file" name="gallery[]" multiple accept="image/*" class="mt-1 w-full text-sm">
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="flex items-center justify-between mb-4">
            <p class="font-bold">ভ্যারিয়েন্ট ও দাম</p>
            <button type="button" onclick="addVariantRow()" class="text-sm text-leaf font-semibold hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">+ ভ্যারিয়েন্ট যোগ</button>
        </div>
        <p class="text-xs text-mute mb-3">একটাই দাম হলে এক রো-ই রাখুন (নাম "Default")। সাইজ/কালার থাকলে প্রতিটার আলাদা রো — প্রতিটার জন্য আলাদা বারকোড অটো তৈরি হবে।</p>

        <div id="variantRows" class="space-y-3">
            @php
                $existing = $product
                    ? $product->variants->map(fn ($v) => ['id' => $v->id, 'variant_name' => $v->variant_name, 'purchase_price' => $v->purchase_price, 'selling_price' => $v->selling_price, 'stock' => $v->inventory->sum('quantity')])->all()
                    : [['id' => null, 'variant_name' => 'Default', 'purchase_price' => '', 'selling_price' => '', 'stock' => '']];
                $existing = old('variants', $existing);
            @endphp
            @foreach ($existing as $i => $v)
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 variant-row">
                    <input type="hidden" name="variants[{{ $i }}][id]" value="{{ $v['id'] ?? '' }}">
                    <input name="variants[{{ $i }}][variant_name]" value="{{ $v['variant_name'] ?? '' }}" placeholder="নাম (লাল / XL)"
                           class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[{{ $i }}][purchase_price]" value="{{ $v['purchase_price'] ?? '' }}" type="number" step="0.01" min="0" placeholder="কেনা দাম"
                           class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    <input name="variants[{{ $i }}][selling_price]" value="{{ $v['selling_price'] ?? '' }}" type="number" step="0.01" min="0" required placeholder="বিক্রয় দাম *"
                           class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    @if (!$product)
                        <input name="variants[{{ $i }}][stock]" value="{{ $v['stock'] ?? '' }}" type="number" min="0" placeholder="শুরুর স্টক"
                               class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
                    @else
                        <span class="text-xs text-mute self-center">স্টক: {{ $v['stock'] ?? 0 }} (ইনভেন্টরি পেজে বদলান)</span>
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
        div.className = 'grid grid-cols-2 md:grid-cols-4 gap-3 variant-row';
        div.innerHTML = `
            <input type="hidden" name="variants[${idx}][id]" value="">
            <input name="variants[${idx}][variant_name]" placeholder="নাম (লাল / XL)" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
            <input name="variants[${idx}][purchase_price]" type="number" step="0.01" min="0" placeholder="কেনা দাম" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
            <input name="variants[${idx}][selling_price]" type="number" step="0.01" min="0" required placeholder="বিক্রয় দাম *" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">
            <input name="variants[${idx}][stock]" type="number" min="0" placeholder="শুরুর স্টক" class="rounded-lg border border-ink/15 px-3 py-2 text-sm">`;
        wrap.appendChild(div);
        idx++;
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
