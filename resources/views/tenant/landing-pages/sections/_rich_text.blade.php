@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" placeholder="যেমন: আমাদের গল্প" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">লেখা</label>
    <textarea name="data[body]" rows="8" class="{{ $input }}">{{ $data['body'] ?? '' }}</textarea>
</div>

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
