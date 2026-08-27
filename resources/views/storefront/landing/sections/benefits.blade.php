@if (!empty($data['items']))
    <section class="max-w-2xl mx-auto">
        @if ($data['heading'] ?? null)
            <h2 class="font-disp font-bold text-2xl text-center mb-6">{{ $data['heading'] }}</h2>
        @endif
        <div class="grid sm:grid-cols-2 gap-4">
            @foreach ($data['items'] as $item)
                <div class="bg-white rounded-card border border-ink/5 p-4 flex gap-3">
                    <span class="text-2xl shrink-0">{{ $item['icon'] ?? '✅' }}</span>
                    <div>
                        <p class="font-semibold">{{ $item['title'] }}</p>
                        @if ($item['description'] ?? null)
                            <p class="text-sm text-mute mt-0.5">{{ $item['description'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
