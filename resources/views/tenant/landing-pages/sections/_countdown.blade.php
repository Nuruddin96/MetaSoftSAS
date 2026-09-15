@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? 'অফারটি শেষ হচ্ছে' }}" maxlength="150" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">অফার শেষ হওয়ার তারিখ/সময়</label>
    <input type="datetime-local" name="data[end_at]" value="{{ $data['end_at'] ?? '' }}" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">সময় শেষ হলে যে লেখা দেখাবে</label>
    <input name="data[expired_text]" value="{{ $data['expired_text'] ?? 'অফারটি শেষ হয়ে গেছে' }}" maxlength="150" class="{{ $input }}">
</div>

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
