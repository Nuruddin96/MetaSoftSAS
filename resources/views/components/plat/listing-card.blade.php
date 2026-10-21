{{--
    Discovery directory card (App\Models\BrandDirectoryListing). Deliberately
    different from x-plat.brand-card: a neutral tinted cover band (no brand
    photography is copied), the brand's own icon from its official website
    when one was saved (otherwise a neutral monogram), and never the
    Verified seal — these brands have not joined MetaSoft BD. Unclaimed
    listings carry a small red dot (title + screen-reader text "Public
    listing, not claimed by the brand"); the section intro explains it.
    The card opens the listing's own /directory/{slug} page.
--}}
@props(['listing'])
@php [$c1, $c2] = $listing->coverGradient(); @endphp
<article class="relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-hair bg-white transition duration-200 hover:-translate-y-0.5 hover:border-leaf/40 hover:shadow-[0_12px_32px_-12px_rgba(10,20,40,0.18)] focus-within:ring-2 focus-within:ring-leaf">
    <div class="relative h-12 sm:h-14" style="background: linear-gradient(135deg, {{ $c1 }}, {{ $c2 }})" aria-hidden="true">
        @if($listing->isUnclaimed())
            <span class="absolute right-2.5 top-2.5 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white" title="Public listing — not claimed by the brand"></span>
        @endif
    </div>
    <div class="flex min-w-0 flex-1 flex-col px-3 pb-3 sm:px-4 sm:pb-4">
        <x-plat.logo :initials="$listing->initials()" from="#475569" to="#1E293B" :size="44" :src="$listing->logoUrl()" :alt="$listing->name.' logo'"
                     class="relative -mt-6 border border-hair object-contain! shadow-sm ring-[3px] ring-white" />
        <h3 class="mt-2 truncate text-[15px] font-extrabold leading-snug text-night" title="{{ $listing->name }}">
            <a href="{{ $listing->url() }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $listing->name }}@if($listing->isUnclaimed())<span class="sr-only"> (public listing, not claimed by the brand)</span>@endif</a>
        </h3>
        <p class="truncate text-[12.5px] font-semibold text-slate2">{{ $listing->category?->name ?? 'Brand' }}</p>
        @if($listing->description)
            <p class="mt-1.5 line-clamp-2 text-[13px] leading-snug text-slate2">{{ $listing->description }}</p>
        @endif
    </div>
</article>
