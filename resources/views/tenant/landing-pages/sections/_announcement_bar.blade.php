@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">লেখা</label>
    <input name="data[text]" value="{{ $data['text'] ?? '' }}" maxlength="150" placeholder="যেমন: 🔥 আজকের অফার — ২০% ছাড়!" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">লিংক (ঐচ্ছিক)</label>
    <input name="data[link_url]" value="{{ $data['link_url'] ?? '' }}" placeholder="https://..." class="{{ $input }}">
</div>

<label class="flex items-center gap-1.5 text-sm">
    <input type="checkbox" name="data[dismissible]" value="1" @checked($data['dismissible'] ?? true)>
    কাস্টমার বন্ধ করতে পারবে (✕ বাটন দেখাবে)
</label>

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
