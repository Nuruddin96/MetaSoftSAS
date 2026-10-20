{{--
    Discovery directory card (App\Models\BrandDirectoryListing). Deliberately
    different from x-plat.brand-card: a neutral text monogram (no logo is
    copied from the brand), a "Public listing" label instead of any badge,
    and never the Verified seal — these brands have not joined MetaSoft BD.
    The card opens the listing's own /directory/{slug} page.
--}}
@props(['listing'])
<article class="relative flex min-w-0 flex-col rounded-xl border border-hair bg-white p-3 transition duration-200 hover:-translate-y-0.5 hover:border-leaf/40 hover:shadow-[0_12px_32px_-12px_rgba(10,20,40,0.18)] focus-within:ring-2 focus-within:ring-leaf sm:p-4">
    <div class="flex items-start justify-between gap-2">
        <x-plat.logo :initials="$listing->initials()" from="#475569" to="#1E293B" :size="44" />
        <span class="rounded bg-cloud px-1.5 text-[10.5px] font-semibold leading-[18px] text-slate2">Public listing</span>
    </div>
    <h3 class="mt-2.5 truncate text-[15px] font-extrabold leading-snug text-night" title="{{ $listing->name }}">
        <a href="{{ $listing->url() }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $listing->name }}</a>
    </h3>
    <p class="truncate text-[12.5px] font-semibold text-slate2">{{ $listing->category?->name ?? 'Brand' }}</p>
    @if($listing->description)
        <p class="mt-1.5 line-clamp-2 text-[13px] leading-snug text-slate2">{{ $listing->description }}</p>
    @endif
</article>
