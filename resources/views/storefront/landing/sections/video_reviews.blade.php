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
                @php $embed = \App\Support\VideoEmbed::url($item['video_url']); @endphp
                <div class="rounded-card overflow-hidden bg-white border border-ink/5">
                    <div class="aspect-video">
                        @if ($embed)
                            <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
                        @else
                            <a href="{{ $item['video_url'] }}" target="_blank" class="w-full h-full grid place-items-center bg-paper text-brand font-semibold text-sm">▶ ভিডিও দেখুন</a>
                        @endif
                    </div>
                    @if ($item['customer_name'] ?? null)
                        <p class="text-sm font-semibold p-3">{{ $item['customer_name'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-landing.section>
@endif
