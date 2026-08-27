@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">প্রোডাক্টের বিস্তারিত বিবরণ</label>
    <textarea name="data[description]" rows="6" class="{{ $input }}">{{ $data['description'] ?? '' }}</textarea>
</div>
