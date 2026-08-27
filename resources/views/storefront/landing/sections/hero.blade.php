@php $embed = \App\Support\VideoEmbed::url($data['video_url'] ?? null); @endphp
<section class="text-center max-w-2xl mx-auto">
    @if ($data['headline'] ?? null)
        <h1 class="font-disp font-extrabold text-3xl md:text-4xl leading-tight">{{ $data['headline'] }}</h1>
    @endif
    @if ($data['subheadline'] ?? null)
        <p class="mt-3 text-lg text-mute">{{ $data['subheadline'] }}</p>
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
    @if (!empty($data['image_path']))
        <div class="mt-6 aspect-square md:aspect-video max-w-md mx-auto rounded-card overflow-hidden bg-white border border-ink/5">
            <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $data['headline'] ?? $product->name }}">
        </div>
    @endif

    <a href="#checkout-section" class="inline-block mt-6 px-10 py-3.5 rounded-btn bg-brand text-white font-bold hover:opacity-90">
        🛒 {{ $data['cta_text'] ?? 'এখনই অর্ডার করুন' }}
    </a>
</section>
