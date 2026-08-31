@if (!empty($data['items']))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        <div class="flex flex-wrap justify-center gap-4">
            @foreach ($data['items'] as $item)
                <div class="flex items-center gap-2 bg-white rounded-btn border border-ink/5 px-4 py-2 text-sm font-medium">
                    <span class="text-lg">{{ $item['icon'] ?? '✅' }}</span>
                    <span>{{ $item['label'] }}</span>
                </div>
            @endforeach
        </div>
    </x-landing.section>
@endif
