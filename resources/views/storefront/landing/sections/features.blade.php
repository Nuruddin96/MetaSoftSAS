@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
@if (($data['heading'] ?? null) || ($data['description'] ?? null))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        <div class="text-left">
            @if ($data['heading'] ?? null)
                <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-3">{{ $data['heading'] }}</h2>
            @endif
            @if ($data['description'] ?? null)
                <div class="bg-white rounded-card border border-ink/5 p-4 text-mute {{ $resolver->bodyClasses($sd) }} leading-relaxed whitespace-pre-line">{{ $data['description'] }}</div>
            @endif
        </div>
    </x-landing.section>
@endif
