@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">বাটনের লেখা</label>
    <input name="data[button_text]" value="{{ $data['button_text'] ?? 'এখনই অর্ডার করুন' }}" maxlength="40" class="{{ $input }}">
    <p class="text-xs text-mute mt-1">ক্লিক করলে পেজের চেকআউট সেকশনে স্ক্রল হয়ে যাবে</p>
</div>
