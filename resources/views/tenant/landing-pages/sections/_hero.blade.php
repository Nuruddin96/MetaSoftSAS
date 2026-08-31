@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">লেআউট</label>
    <select name="data[layout]" class="{{ $input }}">
        <option value="centered" @selected(($data['layout'] ?? 'centered') === 'centered')>মাঝে (Centered)</option>
        <option value="split" @selected(($data['layout'] ?? '') === 'split')>ছবি পাশে (Split)</option>
        <option value="full_bg" @selected(($data['layout'] ?? '') === 'full_bg')>ফুল ব্যাকগ্রাউন্ড</option>
    </select>
    @if (($data['layout'] ?? '') === 'full_bg')
        <p class="text-xs text-mute mt-1">ফুল ব্যাকগ্রাউন্ডের জন্য নিচের "ডিজাইন কাস্টমাইজ করুন" থেকে ব্যাকগ্রাউন্ড ছবি দিন।</p>
    @endif
</div>

<div>
    <label class="text-sm font-medium">হেডলাইন</label>
    <input name="data[headline]" value="{{ $data['headline'] ?? '' }}" required maxlength="150" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">সাব-হেডলাইন</label>
    <input name="data[subheadline]" value="{{ $data['subheadline'] ?? '' }}" maxlength="200" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">ছবি</label>
    <div class="mt-1 flex items-center gap-3">
        @if (!empty($data['image_path']))
            <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-16 h-16 rounded-btn object-cover border border-ink/10">
        @endif
        <input type="file" name="data[image]" accept="image/*" class="flex-1 text-sm">
    </div>
    @if (!empty($data['image_path']))
        <label class="mt-2 flex items-center gap-1.5 text-sm text-mute">
            <input type="checkbox" name="data[remove_image]" value="1"> ছবি সরান
        </label>
    @endif
</div>

<div>
    <label class="text-sm font-medium">ভিডিও লিংক (ঐচ্ছিক — YouTube/Facebook)</label>
    <input name="data[video_url]" value="{{ $data['video_url'] ?? '' }}" placeholder="https://..." class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">CTA বাটনের লেখা</label>
    <input name="data[cta_text]" value="{{ $data['cta_text'] ?? 'এখনই অর্ডার করুন' }}" maxlength="40" class="{{ $input }}">
    <p class="text-xs text-mute mt-1">ক্লিক করলে পেজের চেকআউট সেকশনে স্ক্রল হয়ে যাবে</p>
</div>

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
