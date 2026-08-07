@extends('layouts.panel')

@section('title', $product ? 'প্রোডাক্ট এডিট' : 'নতুন প্রোডাক্ট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">{{ $product ? 'প্রোডাক্ট এডিট' : 'নতুন প্রোডাক্ট' }}</h1>

<form method="POST" enctype="multipart/form-data"
      action="{{ $product ? route('tenant.products.update', $product) : route('tenant.products.store') }}"
      class="max-w-3xl space-y-6">
    @csrf
    @if ($product) @method('PUT') @endif

    <div class="bg-white rounded-xl border border-ink/5 p-6 space-y-4">
        <div>
            <label class="text-sm font-medium">প্রোডাক্টের নাম *</label>
            <input name="name" value="{{ old('name', $product?->name) }}" required
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">ক্যাটাগরি</label>
                <select name="category_id" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-white">
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
                class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">{{ old('description', $product?->description) }}</textarea>
        </div>
        @if ($product)
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked($product->is_active)> অ্যাক্টিভ (দোকানে দেখাবে)
            </label>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <div class="flex items-center justify-between mb-4">
            <p class="font-bold">ভ্যারিয়েন্ট ও দাম</p>
            <button type="button" onclick="addVariantRow()" class="text-sm text-leaf font-semibold hover:underline">+ ভ্যারিয়েন্ট যোগ</button>
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
    </div>

    <button class="px-6 py-3 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
        {{ $product ? 'আপডেট করুন' : 'প্রোডাক্ট যোগ করুন' }}
    </button>
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
</script>
@endpush
@endsection
