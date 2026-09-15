@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
@if (!empty($data['items']))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        @if ($data['heading'] ?? null)
            <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-6">{{ $data['heading'] }}</h2>
        @endif
        <div class="space-y-2 text-left">
            @foreach ($data['items'] as $item)
                <details class="bg-white rounded-card border border-ink/5 p-4 group">
                    <summary class="font-semibold cursor-pointer list-none flex items-center justify-between gap-2">
                        {{ $item['question'] }}
                        <span class="text-mute group-open:rotate-45 transition shrink-0">+</span>
                    </summary>
                    @if ($item['answer'] ?? null)
                        <p class="{{ $resolver->bodyClasses($sd) }} text-mute mt-2">{{ $item['answer'] }}</p>
                    @endif
                </details>
            @endforeach
        </div>
    </x-landing.section>
@endif
