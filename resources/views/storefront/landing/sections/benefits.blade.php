@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $sd = $resolver->resolveSection($global, $data['design'] ?? null);
@endphp
@if (!empty($data['items']))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        @if ($data['heading'] ?? null)
            <h2 class="{{ $resolver->headingFontClass($global) }} font-bold {{ $resolver->headingClasses($sd) }} mb-6">{{ $data['heading'] }}</h2>
        @endif
        <div class="grid sm:grid-cols-2 gap-4 text-left">
            @foreach ($data['items'] as $item)
                <div class="bg-white rounded-card border border-ink/5 p-4 flex gap-3">
                    <span class="text-2xl shrink-0">{{ $item['icon'] ?? '✅' }}</span>
                    <div>
                        <p class="font-semibold">{{ $item['title'] }}</p>
                        @if ($item['description'] ?? null)
                            <p class="{{ $resolver->bodyClasses($sd) }} text-mute mt-0.5">{{ $item['description'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-landing.section>
@endif
