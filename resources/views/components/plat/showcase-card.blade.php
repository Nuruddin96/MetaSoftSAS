{{--
    Homepage "Our Brands" card (central/home/our-brands.blade.php): a short
    cover strip (or the brand gradient), an overlapping logo, then the name
    with the blue MetaSoft BD Verified seal beside it, but only when
    `verified` is true (brands.is_verified, set by Super Admin). Same labels
    as x-plat.brand-card: "Sponsored" for paid placements, "Sample" for
    sample fill-ins. The whole card opens the brand's /brand/{slug} page.
    The directory and profile pages keep using x-plat.brand-card.
--}}
@props(['brand'])
<article class="group relative min-w-0 overflow-hidden rounded-xl border border-hair bg-white transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_14px_32px_-18px_rgba(11,37,69,0.55)] focus-within:ring-2 focus-within:ring-leaf">
    <div class="relative h-[72px] sm:h-24" style="background: linear-gradient(135deg, {{ $brand['from'] }}, {{ $brand['to'] }})">
        @if($brand['cover'] ?? null)<img src="{{ $brand['cover'] }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]">@endif
        <span class="absolute inset-0 bg-gradient-to-t from-black/25 to-transparent"></span>
        @if(($brand['tag'] ?? null) === 'sponsored')
            <span class="absolute right-2 top-2 rounded bg-white/95 px-1.5 text-[10.5px] font-bold leading-[18px] text-slate2" title="Paid placement">Sponsored</span>
        @elseif($brand['sample'] ?? false)
            <span class="absolute right-2 top-2 rounded bg-white/95 px-1.5 text-[10.5px] font-semibold leading-[18px] text-slate2">Sample</span>
        @endif
    </div>
    <div class="px-3 pb-3 sm:px-3.5 sm:pb-3.5">
        <x-plat.logo :initials="$brand['initials']" :from="$brand['from']" :to="$brand['to']" :size="48" :src="$brand['logo'] ?? null" :alt="$brand['name'].' logo'"
                     class="relative -mt-6 border border-hair shadow-sm ring-[3px] ring-white" />
        <h3 class="mt-1.5 flex min-w-0 items-center gap-1.5">
            <a href="{{ $brand['url'] }}" class="truncate text-[14.5px] font-bold leading-snug text-night after:absolute after:inset-0 focus-visible:outline-none" title="{{ $brand['name'] }}">{{ $brand['name'] }}</a>
            @if($brand['verified'] ?? false)<x-plat.verified size="w-[18px] h-[18px]" class="relative" />@endif
        </h3>
        <p class="truncate text-[12.5px] text-slate2">{{ $brand['category'] }}@if($brand['district'] ?? null) · {{ $brand['district'] }}@endif</p>
    </div>
</article>
