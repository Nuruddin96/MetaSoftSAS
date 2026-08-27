@php $input = 'w-full rounded-btn border border-ink/15 px-3 py-2 text-sm'; $items = $data['items'] ?? []; @endphp

<div>
    <label class="text-sm font-medium">সেকশন হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="mt-1 {{ $input }}">
</div>

<p class="text-xs text-mute">সর্বোচ্চ ৬টি প্রশ্ন যোগ করতে পারবেন — প্রশ্ন ফাঁকা রাখলে সেই আইটেমটি বাদ যাবে</p>

@for ($i = 0; $i < 6; $i++)
    <div class="space-y-2 border border-ink/10 rounded-btn p-3">
        <input name="data[items][{{ $i }}][question]" value="{{ $items[$i]['question'] ?? '' }}" maxlength="200" placeholder="প্রশ্ন" class="{{ $input }}">
        <input name="data[items][{{ $i }}][answer]" value="{{ $items[$i]['answer'] ?? '' }}" maxlength="500" placeholder="উত্তর" class="{{ $input }}">
    </div>
@endfor
