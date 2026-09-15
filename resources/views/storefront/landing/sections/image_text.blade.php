@php
    $imageRight = ($data['layout'] ?? 'image-left') === 'image-right';
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
    $stack = $sd['layout']['stack_mobile'] ?? true;
@endphp
@if (!empty($data['image_path']) || ($data['heading'] ?? null) || ($data['description'] ?? null))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        <div class="grid {{ $stack ? 'md:grid-cols-2' : 'grid-cols-2' }} gap-6 items-center text-left">
            @if (!empty($data['image_path']))
                <div class="aspect-square rounded-card overflow-hidden bg-white border border-ink/5 {{ $imageRight ? ($stack ? 'md:order-2' : 'order-2') : '' }}">
                    <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $data['heading'] ?? $product->name }}">
                </div>
            @endif
            <div>
                @if ($data['heading'] ?? null)
                    <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }}">{{ $data['heading'] }}</h2>
                @endif
                @if ($data['description'] ?? null)
                    <p class="mt-2 text-mute {{ $resolver->bodyClasses($sd) }} leading-relaxed whitespace-pre-line">{{ $data['description'] }}</p>
                @endif
            </div>
        </div>
    </x-landing.section>
@endif
