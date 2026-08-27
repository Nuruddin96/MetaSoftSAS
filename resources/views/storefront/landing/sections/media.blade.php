@php $embed = \App\Support\VideoEmbed::url($data['video_url'] ?? null); @endphp
@if ($embed || !empty($data['image_path']))
    <section class="max-w-2xl mx-auto">
        <div class="aspect-video rounded-card overflow-hidden bg-white border border-ink/5">
            @if ($embed)
                <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
            @else
                <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $product->name }}">
            @endif
        </div>
    </section>
@endif
