@if (!empty($data['images']))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        <div class="grid grid-cols-3 gap-2">
            @foreach ($data['images'] as $path)
                <div class="aspect-square rounded-card overflow-hidden bg-white border border-ink/5">
                    <img src="{{ asset('storage/' . $path) }}" class="w-full h-full object-cover" loading="lazy" alt="{{ $product->name }}">
                </div>
            @endforeach
        </div>
    </x-landing.section>
@endif
