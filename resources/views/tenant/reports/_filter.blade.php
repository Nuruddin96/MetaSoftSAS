<form class="flex flex-wrap items-end gap-3 mb-6">
    <div>
        <label class="text-xs text-mute">শুরু</label>
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="block rounded-btn border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
    </div>
    <div>
        <label class="text-xs text-mute">শেষ</label>
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="block rounded-btn border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
    </div>
    <x-ui.button type="submit" variant="primary" size="sm">দেখুন</x-ui.button>
    <div class="flex gap-2 text-xs">
        <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}" class="px-3 py-2 rounded-btn bg-white border border-ink/10 hover:border-leaf/40 transition">আজ</a>
        <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}" class="px-3 py-2 rounded-btn bg-white border border-ink/10 hover:border-leaf/40 transition">৭ দিন</a>
        <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="px-3 py-2 rounded-btn bg-white border border-ink/10 hover:border-leaf/40 transition">এ মাস</a>
    </div>
</form>
