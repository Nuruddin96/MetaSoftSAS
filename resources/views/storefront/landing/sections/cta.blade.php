@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
<x-landing.section :global="$global" :design="$data['design'] ?? null" class="bg-brand/5">
    @if ($data['heading'] ?? null)
        <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }}">{{ $data['heading'] }}</h2>
    @endif
    <a href="#checkout-section" class="mt-4 {{ $resolver->buttonClasses($global) }}">
        🛒 {{ $data['button_text'] ?? 'এখনই অর্ডার করুন' }}
    </a>
</x-landing.section>
