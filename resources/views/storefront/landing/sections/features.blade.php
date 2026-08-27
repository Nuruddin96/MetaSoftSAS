@if (($data['heading'] ?? null) || ($data['description'] ?? null))
    <section class="max-w-2xl mx-auto">
        @if ($data['heading'] ?? null)
            <h2 class="font-disp font-bold text-2xl mb-3">{{ $data['heading'] }}</h2>
        @endif
        @if ($data['description'] ?? null)
            <div class="bg-white rounded-card border border-ink/5 p-4 text-mute leading-relaxed whitespace-pre-line">{{ $data['description'] }}</div>
        @endif
    </section>
@endif
