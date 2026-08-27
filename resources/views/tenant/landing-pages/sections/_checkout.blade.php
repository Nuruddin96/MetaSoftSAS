@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? 'অর্ডার করুন' }}" maxlength="150" class="{{ $input }}">
</div>

<p class="text-xs text-mute">প্রোডাক্ট, ভ্যারিয়েন্ট বাছাই, পরিমাণ, নাম, ফোন, ঠিকানা ও অর্ডার বাটন — এই সব স্বয়ংক্রিয়ভাবে যোগ হবে, আলাদা করে সেট করার দরকার নেই।</p>
