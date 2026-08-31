@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
<x-landing.section :global="$global" :design="$data['design'] ?? null">
    <div class="bg-white rounded-card border border-ink/5 p-5 text-left">
        @if ($data['heading'] ?? null)
            <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-3">{{ $data['heading'] }}</h2>
        @endif
        <div class="grid sm:grid-cols-2 gap-3 {{ $resolver->bodyClasses($sd) }}">
            <div class="flex items-center justify-between bg-paper rounded-btn px-4 py-3">
                <span class="text-mute">ঢাকার ভিতরে</span>
                <span class="font-semibold">{{ number_format($chargeInside ?? 0) }}৳</span>
            </div>
            <div class="flex items-center justify-between bg-paper rounded-btn px-4 py-3">
                <span class="text-mute">ঢাকার বাইরে</span>
                <span class="font-semibold">{{ number_format($chargeOutside ?? 0) }}৳</span>
            </div>
        </div>
        @if ($data['eta_text'] ?? null)
            <p class="mt-3 text-mute {{ $resolver->bodyClasses($sd) }}">🚚 {{ $data['eta_text'] }}</p>
        @endif
        @if ($data['note'] ?? null)
            <p class="mt-2 text-mute {{ $resolver->bodyClasses($sd) }} whitespace-pre-line">{{ $data['note'] }}</p>
        @endif
    </div>
</x-landing.section>
