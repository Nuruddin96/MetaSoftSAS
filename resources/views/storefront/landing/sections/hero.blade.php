@php
    $embed = \App\Support\VideoEmbed::url($data['video_url'] ?? null);
    $sd = app(\App\Services\LandingPage\DesignResolver::class)->resolveSection($global, $data['design'] ?? null);
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $layout = $data['layout'] ?? 'centered';
    $media = $embed || !empty($data['image_path']);
@endphp
<x-landing.section :global="$global" :design="$data['design'] ?? null" id="hero-section">
    @if ($layout === 'split' && $media)
        <div class="grid md:grid-cols-2 gap-6 items-center text-left">
            <div class="md:order-2">
                @if ($embed)
                    <div class="aspect-video rounded-card overflow-hidden bg-white border border-ink/5">
                        <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
                    </div>
                @elseif (!empty($data['image_path']))
                    <div class="aspect-square md:aspect-video rounded-card overflow-hidden bg-white border border-ink/5">
                        <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $data['headline'] ?? $product->name }}">
                    </div>
                @endif
            </div>
            <div class="md:order-1">
                @if ($data['headline'] ?? null)
                    <h1 class="{{ $resolver->headingFontClass($global) }} font-extrabold {{ $resolver->headingClasses($sd) }} leading-tight">{{ $data['headline'] }}</h1>
                @endif
                @if ($data['subheadline'] ?? null)
                    <p class="mt-3 {{ $resolver->bodyClasses($sd) }} text-mute">{{ $data['subheadline'] }}</p>
                @endif
                <a href="#checkout-section" class="mt-6 {{ $resolver->buttonClasses($global) }}">
                    🛒 {{ $data['cta_text'] ?? 'এখনই অর্ডার করুন' }}
                </a>
            </div>
        </div>
    @else
        {{-- centered and full_bg both use this stacked layout; full_bg's own image renders as the
             section background (set via data.design.background) rather than an inline block, so a
             tenant picks "Full background" by choosing hero layout=full_bg and setting the section's
             Design > Background to that same image — no separate image field to keep in sync. --}}
        @if ($data['headline'] ?? null)
            <h1 class="{{ $resolver->headingFontClass($global) }} font-extrabold {{ $resolver->headingClasses($sd) }} leading-tight">{{ $data['headline'] }}</h1>
        @endif
        @if ($data['subheadline'] ?? null)
            <p class="mt-3 {{ $resolver->bodyClasses($sd) }} text-mute">{{ $data['subheadline'] }}</p>
        @endif

        {{--
            Image and video are independent — each renders in its own slot
            when present, never an @if/@elseif choosing one over the other.
            Previously a video only showed when there was no image at all,
            so an auto-populated product image (which had no "remove" control
            until this fix) silently hid a video the tenant had just added.
        --}}
        @if ($embed)
            <div class="mt-6 aspect-video max-w-md mx-auto rounded-card overflow-hidden bg-white border border-ink/5">
                <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
            </div>
        @endif
        @if (!empty($data['image_path']) && $layout !== 'full_bg')
            <div class="mt-6 aspect-square md:aspect-video max-w-md mx-auto rounded-card overflow-hidden bg-white border border-ink/5">
                <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $data['headline'] ?? $product->name }}">
            </div>
        @endif

        <a href="#checkout-section" class="inline-block mt-6 {{ $resolver->buttonClasses($global) }}">
            🛒 {{ $data['cta_text'] ?? 'এখনই অর্ডার করুন' }}
        </a>
    @endif
</x-landing.section>
