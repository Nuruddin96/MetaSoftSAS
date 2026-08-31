@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? 'ডেলিভারি তথ্য' }}" maxlength="150" class="{{ $input }}">
</div>

<p class="text-xs text-mute">ঢাকার ভিতরে/বাইরের চার্জ আপনার স্টোর সেটিংস থেকে স্বয়ংক্রিয়ভাবে দেখানো হবে।</p>

<div>
    <label class="text-sm font-medium">ডেলিভারি সময় (ঐচ্ছিক)</label>
    <input name="data[eta_text]" value="{{ $data['eta_text'] ?? '' }}" maxlength="150" placeholder="যেমন: ২৪–৪৮ ঘণ্টার মধ্যে ডেলিভারি" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">অতিরিক্ত নোট (ঐচ্ছিক)</label>
    <textarea name="data[note]" rows="3" class="{{ $input }}">{{ $data['note'] ?? '' }}</textarea>
</div>

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
