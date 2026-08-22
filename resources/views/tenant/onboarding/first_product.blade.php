@extends('layouts.onboarding')

@section('title', 'প্রথম প্রোডাক্ট')

@section('content')
<h1 class="font-disp font-bold text-xl sm:text-2xl mb-1.5">আপনার প্রথম প্রোডাক্ট যোগ করবেন?</h1>
<p class="text-mute text-sm mb-6">চাইলে এখনই একটা প্রোডাক্ট যোগ করুন, নয়তো স্কিপ করে সরাসরি ড্যাশবোর্ডে যান — পরেও যোগ করতে পারবেন।</p>

<form method="POST" action="{{ route('tenant.onboarding.first_product.store') }}" enctype="multipart/form-data" class="space-y-4" id="firstProductForm">
    @csrf
    <input type="hidden" name="thumbnail_path" id="thumbnail_path" value="">

    <div>
        <label class="text-sm font-medium">প্রোডাক্টের নাম</label>
        <input type="text" name="name" id="name" value="{{ old('name') }}" maxlength="255" required
               class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="text-sm font-medium">দাম (৳)</label>
            <input type="number" step="0.01" min="0" name="selling_price" value="{{ old('selling_price') }}" required
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
        <div>
            <label class="text-sm font-medium">ক্যাটাগরি (ঐচ্ছিক)</label>
            <select name="category_id" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <option value="">নির্বাচন করুন</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if (count($attributeSuggestions))
        <p class="text-xs text-mute">এই ধরনের পণ্যে সাধারণত এসব বৈশিষ্ট্য থাকে: {{ implode(', ', $attributeSuggestions) }} — বিস্তারিত ভ্যারিয়েন্ট পরে প্রোডাক্ট পেজ থেকে যোগ করতে পারবেন।</p>
    @endif

    <div>
        <label class="text-sm font-medium">ছবি (ঐচ্ছিক)</label>
        <input type="file" name="thumbnail" id="thumbnail" accept="image/*"
               class="mt-1 w-full text-sm rounded-lg border border-ink/15 px-3 py-2.5">
        <img id="thumbPreview" class="hidden mt-2 w-24 h-24 object-cover rounded-lg border border-ink/10">
    </div>

    <div>
        <div class="flex items-center justify-between mb-1">
            <label class="text-sm font-medium">বর্ণনা (ঐচ্ছিক)</label>
            <button type="button" id="aiDescribeBtn"
                    class="text-xs font-semibold text-leafdk hover:underline inline-flex items-center gap-1">
                <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> ছবি দেখে বর্ণনা লিখে দাও
            </button>
        </div>
        <textarea name="description" id="description" rows="3" maxlength="2000"
                  class="w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">{{ old('description') }}</textarea>
        <p id="aiStatus" class="text-xs text-mute mt-1"></p>
    </div>

    <button type="submit" class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
        প্রোডাক্ট সেভ করুন
    </button>
</form>

<form method="POST" action="{{ route('tenant.onboarding.first_product.skip') }}" class="mt-3">
    @csrf
    <button type="submit" class="w-full py-3 rounded-xl text-mute font-semibold text-sm hover:bg-paper">
        এখন স্কিপ করুন
    </button>
</form>

<script>
(function () {
    const fileInput = document.getElementById('thumbnail');
    const aiBtn = document.getElementById('aiDescribeBtn');
    const descField = document.getElementById('description');
    const preview = document.getElementById('thumbPreview');
    const thumbPathInput = document.getElementById('thumbnail_path');
    const aiStatus = document.getElementById('aiStatus');
    const nameField = document.getElementById('name');

    aiBtn.addEventListener('click', async () => {
        if (!fileInput.files[0]) {
            aiStatus.textContent = 'আগে একটি ছবি বেছে নিন।';
            return;
        }

        aiBtn.disabled = true;
        aiStatus.textContent = 'ছবি বিশ্লেষণ করে বর্ণনা লেখা হচ্ছে...';

        const fd = new FormData();
        fd.append('image', fileInput.files[0]);
        fd.append('product_name', nameField.value || '');

        try {
            const res = await fetch(@json(route('tenant.onboarding.describe_image')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': window.__csrf, 'Accept': 'application/json' },
                body: fd,
            });
            const data = await res.json();

            if (data.success) {
                descField.value = data.description;
                thumbPathInput.value = data.image_path;
                preview.src = data.image_url;
                preview.classList.remove('hidden');
                fileInput.value = ''; // already stored server-side; avoid re-uploading on submit
                aiStatus.textContent = 'বর্ণনা তৈরি হয়েছে — চাইলে এডিট করুন।';
            } else {
                aiStatus.textContent = data.message || 'বর্ণনা তৈরি করা যায়নি। আবার চেষ্টা করুন।';
            }
        } catch (e) {
            aiStatus.textContent = 'নেটওয়ার্ক সমস্যা — আবার চেষ্টা করুন।';
        } finally {
            aiBtn.disabled = false;
        }
    });
})();
</script>
@endsection
