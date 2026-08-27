@php $imageRight = ($data['layout'] ?? 'image-left') === 'image-right'; @endphp
@if (!empty($data['image_path']) || ($data['heading'] ?? null) || ($data['description'] ?? null))
    <section class="max-w-2xl mx-auto grid md:grid-cols-2 gap-6 items-center">
        @if (!empty($data['image_path']))
            <div class="aspect-square rounded-card overflow-hidden bg-white border border-ink/5 {{ $imageRight ? 'md:order-2' : '' }}">
                <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $data['heading'] ?? $product->name }}">
            </div>
        @endif
        <div>
            @if ($data['heading'] ?? null)
                <h2 class="font-disp font-bold text-2xl">{{ $data['heading'] }}</h2>
            @endif
            @if ($data['description'] ?? null)
                <p class="mt-2 text-mute leading-relaxed whitespace-pre-line">{{ $data['description'] }}</p>
            @endif
        </div>
    </section>
@endif
