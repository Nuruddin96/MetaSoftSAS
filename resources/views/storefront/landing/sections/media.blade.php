@php $embed = \App\Support\VideoEmbed::url($data['video_url'] ?? null); @endphp
{{-- Image and video are independent slots — see hero.blade.php's comment for why this used to matter. --}}
@if ($embed || !empty($data['image_path']))
    <x-landing.section :global="$global" :design="$data['design'] ?? null">
        @if ($embed)
            <div class="aspect-video rounded-card overflow-hidden bg-white border border-ink/5">
                <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
            </div>
        @endif
        @if (!empty($data['image_path']))
            <div class="aspect-video rounded-card overflow-hidden bg-white border border-ink/5 {{ $embed ? 'mt-4' : '' }}">
                <img src="{{ asset('storage/' . $data['image_path']) }}" class="w-full h-full object-cover" alt="{{ $product->name }}">
            </div>
        @endif
    </x-landing.section>
@endif
