@if (!empty($data['images']))
    <section class="max-w-2xl mx-auto">
        <div class="grid grid-cols-3 gap-2">
            @foreach ($data['images'] as $path)
                <div class="aspect-square rounded-card overflow-hidden bg-white border border-ink/5">
                    <img src="{{ asset('storage/' . $path) }}" class="w-full h-full object-cover" loading="lazy" alt="{{ $product->name }}">
                </div>
            @endforeach
        </div>
    </section>
@endif
