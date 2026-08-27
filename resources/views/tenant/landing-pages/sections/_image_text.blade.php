@php $input = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none'; @endphp

<div>
    <label class="text-sm font-medium">ছবি</label>
    <div class="mt-1 flex items-center gap-3">
        @if (!empty($data['image_path']))
            <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-16 h-16 rounded-btn object-cover border border-ink/10">
        @endif
        <input type="file" name="data[image]" accept="image/*" class="flex-1 text-sm">
    </div>
</div>

<div>
    <label class="text-sm font-medium">হেডিং</label>
    <input name="data[heading]" value="{{ $data['heading'] ?? '' }}" maxlength="150" class="{{ $input }}">
</div>

<div>
    <label class="text-sm font-medium">বর্ণনা</label>
    <textarea name="data[description]" rows="4" class="{{ $input }}">{{ $data['description'] ?? '' }}</textarea>
</div>

<div>
    <label class="text-sm font-medium">ছবি কোন পাশে থাকবে</label>
    <select name="data[layout]" class="{{ $input }}">
        <option value="image-left" @selected(($data['layout'] ?? '') === 'image-left')>বামে</option>
        <option value="image-right" @selected(($data['layout'] ?? '') === 'image-right')>ডানে</option>
    </select>
</div>
