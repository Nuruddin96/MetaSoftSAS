@extends('layouts.super')
@section('title', $product ? 'পণ্য এডিট' : 'নতুন পণ্য')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">{{ $product ? 'পণ্য এডিট' : 'নতুন পণ্য যোগ করুন' }}</h1>

<form method="POST" enctype="multipart/form-data"
      action="{{ $product ? route('super.source.products.update', $product) : route('super.source.products.store') }}"
      class="max-w-2xl bg-white rounded-xl border border-ink/5 p-6 space-y-4">
    @csrf
    @if ($product) @method('PUT') @endif

    <div>
        <label class="text-sm font-medium">পণ্যের নাম *</label>
        <input name="name" value="{{ old('name', $product?->name) }}" required class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
    </div>
    <div>
        <label class="text-sm font-medium">ছবি (একাধিক দেওয়া যাবে, প্রথমটা প্রধান ছবি হিসেবে দেখাবে)</label>
        <input type="file" name="images[]" accept="image/*" multiple class="mt-1 w-full text-sm">
        @if ($product?->images->isNotEmpty())
            <div class="flex flex-wrap gap-2 mt-3">
                @foreach ($product->images as $img)
                    <div class="relative group">
                        <img src="{{ asset('storage/'.$img->image_path) }}" class="w-20 h-20 object-cover rounded border border-ink/10">
                        <form method="POST" action="{{ route('super.source.products.image.destroy', $img) }}" onsubmit="return confirm('ছবিটা মুছবেন?')"
                              class="absolute -top-2 -right-2">
                            @csrf @method('DELETE')
                            <button class="w-6 h-6 rounded-full bg-red-600 text-white text-xs">✕</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    <div>
        <label class="text-sm font-medium">বর্ণনা</label>
        <textarea name="description" rows="4" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">{{ old('description', $product?->description) }}</textarea>
    </div>
    <div class="grid md:grid-cols-2 gap-4">
        <div>
            <label class="text-sm font-medium">সর্বনিম্ন দাম *</label>
            <input name="unit_price" type="number" step="0.01" min="0" required value="{{ old('unit_price', $product?->unit_price) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">সর্বোচ্চ দাম (ঐচ্ছিক — রেঞ্জ দেখাতে চাইলে)</label>
            <input name="max_price" type="number" step="0.01" min="0" value="{{ old('max_price', $product?->max_price) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">সর্বনিম্ন অর্ডার পরিমাণ</label>
            <input name="min_order_qty" type="number" min="1" value="{{ old('min_order_qty', $product?->min_order_qty ?? 1) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">ডেলিভারি সময়</label>
            <input name="delivery_time_days" value="{{ old('delivery_time_days', $product?->delivery_time_days) }}" placeholder="যেমন: ৭-১০ দিন" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
        <div>
            <label class="text-sm font-medium">শিপিং খরচ</label>
            <input name="shipping_cost" type="number" step="0.01" min="0" value="{{ old('shipping_cost', $product?->shipping_cost ?? 0) }}" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($product?->is_active ?? true)> চালু (টেনেন্টরা দেখতে পাবে)</label>

    <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">{{ $product ? 'আপডেট করুন' : 'যোগ করুন' }}</button>
</form>
@endsection
