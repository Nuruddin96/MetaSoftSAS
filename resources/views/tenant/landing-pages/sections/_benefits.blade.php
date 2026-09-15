@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; $items = $data['items'] ?? []; @endphp

<div>
    <label class="text-sm font-medium">সেকশন হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="{{ $input }}">
</div>

<p class="text-xs text-mute">সর্বোচ্চ ৬টি সুবিধা যোগ করতে পারবেন — শিরোনাম ফাঁকা রাখলে সেই আইটেমটি বাদ যাবে</p>

@for ($i = 0; $i < 6; $i++)
    <div class="grid grid-cols-[3.5rem_1fr] gap-2 items-start border border-ink/10 rounded-btn p-2">
        <input name="data[items][{{ $i }}][icon]" value="{{ $items[$i]['icon'] ?? '' }}" maxlength="4" placeholder="✅"
               class="rounded-btn border border-ink/15 px-2 py-2 text-center text-lg">
        <div class="space-y-2">
            <input name="data[items][{{ $i }}][title]" value="{{ $items[$i]['title'] ?? '' }}" maxlength="100" placeholder="শিরোনাম"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2 text-sm">
            <input name="data[items][{{ $i }}][description]" value="{{ $items[$i]['description'] ?? '' }}" maxlength="200" placeholder="ছোট বর্ণনা (ঐচ্ছিক)"
                   class="w-full rounded-btn border border-ink/15 px-3 py-2 text-sm">
        </div>
    </div>
@endfor

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
