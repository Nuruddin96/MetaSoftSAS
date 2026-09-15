@php $input = 'w-full rounded-btn border border-ink/15 px-3 py-2 text-sm'; $items = $data['items'] ?? []; @endphp

<div>
    <label class="text-sm font-medium">সেকশন হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="mt-1 {{ $input }}">
</div>

<p class="text-xs text-mute">সর্বোচ্চ ৬টি ভিডিও যোগ করতে পারবেন — লিংক ফাঁকা রাখলে সেই আইটেমটি বাদ যাবে</p>

@for ($i = 0; $i < 6; $i++)
    <div class="flex gap-2 border border-ink/10 rounded-btn p-3">
        <input name="data[items][{{ $i }}][customer_name]" value="{{ $items[$i]['customer_name'] ?? '' }}" maxlength="150" placeholder="কাস্টমারের নাম (ঐচ্ছিক)" class="{{ $input }} w-1/3">
        <input name="data[items][{{ $i }}][video_url]" value="{{ $items[$i]['video_url'] ?? '' }}" placeholder="ভিডিও লিংক (YouTube/Facebook)" class="{{ $input }} flex-1">
    </div>
@endfor

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
