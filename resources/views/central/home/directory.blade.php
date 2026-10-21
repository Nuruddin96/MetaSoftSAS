{{--
    Well-known brands in Bangladesh — discovery listings picked for the
    homepage (HomepageContent::directoryPicks(), always 8 cards when 8 are public). Kept apart from
    "Our Brands": these businesses were listed by the MetaSoft BD team from
    their official websites and have not joined or been verified, so the
    cards carry a red dot while unclaimed (explained in the intro) and never the Verified seal.
    Hidden entirely when there are no listings.
--}}
@if(($directoryPicks ?? collect())->isNotEmpty())
<section id="directory" class="scroll-mt-20 border-t border-hair bg-cloud py-8 sm:py-12" aria-labelledby="directoryTitle">
    <div class="{{ $wrap }}">
        <div class="flex items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Brand directory</p>
                <h2 id="directoryTitle" class="{{ $h2 }}">Well-known brands in Bangladesh</h2>
                <p class="mt-1.5 max-w-3xl text-[14.5px] text-slate2">Compiled from each brand’s official website so you can find them in one place. Listed for discovery — not members of, partners of or verified by MetaSoft BD. <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-red-500 align-middle" aria-hidden="true"></span>Red dot = public listing, not claimed by the brand.</span></p>
            </div>
            <a href="{{ route('brands.index') }}#listings" class="hidden shrink-0 items-center gap-1 text-sm font-bold text-leaf hover:underline sm:inline-flex">Full directory <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4 lg:gap-4">
            @foreach($directoryPicks as $listing)
                <x-plat.listing-card :listing="$listing" />
            @endforeach
        </div>

        <div class="mt-5 flex justify-center sm:hidden">
            <a href="{{ route('brands.index') }}#listings" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border border-hair bg-white px-6 text-[15px] font-bold text-night hover:border-leaf">View the full directory <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
        </div>
    </div>
</section>
@endif
