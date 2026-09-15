@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
@if (($data['heading'] ?? null) || ($data['body'] ?? null))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        <div class="text-left">
            @if ($data['heading'] ?? null)
                <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-3">{{ $data['heading'] }}</h2>
            @endif
            @if ($data['body'] ?? null)
                <div class="text-mute {{ $resolver->bodyClasses($sd) }} leading-relaxed whitespace-pre-line">{{ $data['body'] }}</div>
            @endif
        </div>
    </x-landing.section>
@endif
