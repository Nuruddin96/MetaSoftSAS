@php $embed = \App\Support\VideoEmbed::url($data['video_url'] ?? null); @endphp
{{-- Image and video are independent slots — see hero.blade.php's comment for why this used to matter. --}}
@if ($embed)
    <section class="max-w-2xl mx-auto">
        <div class="aspect-video rounded-card overflow-hidden bg-white border border-ink/5">
            <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
        </div>
    </section>
@endif
@if (!empty($data['image_path']))
    <section class="max-w-2xl mx-auto">
        <div class="aspect-video rounded-card overflow-hidden bg-white border border-ink/5">
            <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $product->name }}">
        </div>
    </section>
@endif
