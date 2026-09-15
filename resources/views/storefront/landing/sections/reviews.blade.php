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
                <div class="bg-white rounded-card border border-ink/5 p-4">
                    <div class="flex items-center gap-3">
                        @if (!empty($item['photo_path']))
                            <img src="{{ asset('storage/' . $item['photo_path']) }}" class="w-10 h-10 rounded-full object-cover" alt="{{ $item['customer_name'] }}">
                        @else
                            <span class="w-10 h-10 rounded-full bg-brand/10 grid place-items-center text-brand font-bold shrink-0">{{ mb_substr($item['customer_name'], 0, 1) }}</span>
                        @endif
                        <div>
                            <p class="font-semibold text-sm">{{ $item['customer_name'] }}</p>
                            <p class="text-amber text-xs">{{ str_repeat('★', $item['rating'] ?? 5) }}{{ str_repeat('☆', 5 - ($item['rating'] ?? 5)) }}</p>
                        </div>
                    </div>
                    @if ($item['review_text'] ?? null)
                        <p class="{{ $resolver->bodyClasses($sd) }} text-mute mt-3">{{ $item['review_text'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-landing.section>
@endif
