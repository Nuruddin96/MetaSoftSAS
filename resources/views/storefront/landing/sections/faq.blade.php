@if (!empty($data['items']))
    <section class="max-w-2xl mx-auto">
        @if ($data['heading'] ?? null)
            <h2 class="font-disp font-bold text-2xl text-center mb-6">{{ $data['heading'] }}</h2>
        @endif
        <div class="space-y-2">
            @foreach ($data['items'] as $item)
                <details class="bg-white rounded-card border border-ink/5 p-4 group">
                    <summary class="font-semibold cursor-pointer list-none flex items-center justify-between gap-2">
                        {{ $item['question'] }}
                        <span class="text-mute group-open:rotate-45 transition shrink-0">+</span>
                    </summary>
                    @if ($item['answer'] ?? null)
                        <p class="text-sm text-mute mt-2">{{ $item['answer'] }}</p>
                    @endif
                </details>
            @endforeach
        </div>
    </section>
@endif
