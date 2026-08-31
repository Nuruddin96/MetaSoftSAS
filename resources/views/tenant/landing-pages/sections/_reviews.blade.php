@php $input = 'w-full rounded-btn border border-ink/15 px-3 py-2 text-sm'; $items = $data['items'] ?? []; @endphp

<div>
    <label class="text-sm font-medium">সেকশন হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="mt-1 {{ $input }}">
</div>

<p class="text-xs text-mute">সর্বোচ্চ ৬টি রিভিউ যোগ করতে পারবেন — কাস্টমারের নাম ফাঁকা রাখলে সেই আইটেমটি বাদ যাবে</p>

@for ($i = 0; $i < 6; $i++)
    <div class="border border-ink/10 rounded-btn p-3 space-y-2">
        <div class="flex gap-2">
            <input name="data[items][{{ $i }}][customer_name]" value="{{ $items[$i]['customer_name'] ?? '' }}" maxlength="150" placeholder="কাস্টমারের নাম" class="{{ $input }}">
            <select name="data[items][{{ $i }}][rating]" class="{{ $input }} w-28">
                @for ($r = 5; $r >= 1; $r--)
                    <option value="{{ $r }}" @selected(($items[$i]['rating'] ?? 5) == $r)>{{ str_repeat('★', $r) }}</option>
                @endfor
            </select>
        </div>
        <input name="data[items][{{ $i }}][review_text]" value="{{ $items[$i]['review_text'] ?? '' }}" maxlength="500" placeholder="রিভিউয়ের লেখা" class="{{ $input }}">
        <div class="flex items-center gap-2">
            @if (!empty($items[$i]['photo_path']))
                <img src="{{ asset('storage/' . $items[$i]['photo_path']) }}" class="w-10 h-10 rounded-full object-cover border border-ink/10">
            @endif
            <input type="file" name="data[items][{{ $i }}][photo]" accept="image/*" class="flex-1 text-xs">
        </div>
    </div>
@endfor

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
