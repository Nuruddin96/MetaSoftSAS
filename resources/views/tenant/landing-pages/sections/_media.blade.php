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
    <label class="text-sm font-medium">অথবা ভিডিও লিংক</label>
    <input name="data[video_url]" value="{{ $data['video_url'] ?? '' }}" placeholder="https://..." class="{{ $input }}">
    <p class="text-xs text-mute mt-1">ভিডিও লিংক দিলে সেটাই দেখাবে, ছবি না</p>
</div>
