<form class="flex flex-wrap items-end gap-3 mb-6">
    <div>
        <label class="text-xs text-mute">শুরু</label>
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="block rounded-lg border border-ink/15 px-3 py-2 text-sm">
    </div>
    <div>
        <label class="text-xs text-mute">শেষ</label>
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="block rounded-lg border border-ink/15 px-3 py-2 text-sm">
    </div>
    <button class="px-5 py-2 rounded-lg bg-ink text-white font-semibold text-sm">দেখুন</button>
    <div class="flex gap-2 text-xs">
        <a href="?from={{ now()->toDateString() }}&to={{ now()->toDateString() }}" class="px-3 py-2 rounded-lg bg-white border border-ink/10 hover:border-leaf/40">আজ</a>
        <a href="?from={{ now()->subDays(6)->toDateString() }}&to={{ now()->toDateString() }}" class="px-3 py-2 rounded-lg bg-white border border-ink/10 hover:border-leaf/40">৭ দিন</a>
        <a href="?from={{ now()->startOfMonth()->toDateString() }}&to={{ now()->toDateString() }}" class="px-3 py-2 rounded-lg bg-white border border-ink/10 hover:border-leaf/40">এ মাস</a>
    </div>
</form>
