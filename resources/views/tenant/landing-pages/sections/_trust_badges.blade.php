@php $items = $data['items'] ?? []; @endphp

<p class="text-xs text-mute">সর্বোচ্চ ৬টি ব্যাজ যোগ করতে পারবেন (যেমন: ✅ ক্যাশ অন ডেলিভারি, 🚚 ফ্রি ডেলিভারি, 🔒 নিরাপদ পেমেন্ট) — লেখা ফাঁকা রাখলে সেই আইটেমটি বাদ যাবে</p>

@for ($i = 0; $i < 6; $i++)
    <div class="grid grid-cols-[3.5rem_1fr] gap-2 border border-ink/10 rounded-btn p-2">
        <input name="data[items][{{ $i }}][icon]" value="{{ $items[$i]['icon'] ?? '' }}" maxlength="4" placeholder="✅"
               class="rounded-btn border border-ink/15 px-2 py-2 text-center text-lg">
        <input name="data[items][{{ $i }}][label]" value="{{ $items[$i]['label'] ?? '' }}" maxlength="60" placeholder="যেমন: ক্যাশ অন ডেলিভারি"
               class="rounded-btn border border-ink/15 px-3 py-2 text-sm">
    </div>
@endfor

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
