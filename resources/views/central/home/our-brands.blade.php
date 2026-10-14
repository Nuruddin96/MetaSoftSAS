{{--
    Our Brands — directly below the hero. HomepageContent::ourBrands():
    editorial picks and labelled sponsored placements first, then every
    other approved brand newest first. Exactly four rows at each .brand-grid
    column count (3 on phones, 4 from 480px, 6 on desktop): the
    extra cards are only revealed once the grid is wide enough for them.
--}}
@php
    $directoryUrl = $realBrands ? route('brands.index') : url('/').'?browse=brands#results';
@endphp
<section id="brands" class="scroll-mt-20 bg-cloud py-8 sm:py-12 lg:py-16" aria-labelledby="ourBrandsTitle">
    <div class="{{ $wrap }}">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="font-body text-[13px] font-semibold text-slate2" lang="bn">আমাদের ব্র্যান্ড</p>
                <h2 id="ourBrandsTitle" class="font-plat text-2xl font-extrabold tracking-tight text-night sm:text-3xl">Our Brands</h2>
            </div>
            <a href="{{ $directoryUrl }}" class="hidden shrink-0 items-center gap-1 text-sm font-bold text-leaf hover:underline sm:inline-flex">View all <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
        </div>

        @if($ourBrands)
            <div class="brand-grid mt-4 sm:mt-6">
                @foreach($ourBrands as $i => $brand)
                    <div @class(['hidden min-[480px]:block' => $i >= 12 && $i < 16, 'hidden lg:block' => $i >= 16])>
                        <x-plat.brand-card :brand="$brand" />
                    </div>
                @endforeach
            </div>
        @else
            <div class="mt-4 rounded-2xl border border-dashed border-hair bg-white px-6 py-10 text-center">
                <p class="font-bold">The first brands are being reviewed right now.</p>
                <a href="{{ route('owner.register') }}" class="mt-3 inline-flex text-sm font-bold text-leaf hover:underline">List your brand — free</a>
            </div>
        @endif

        <div class="mt-5 flex justify-center sm:mt-8">
            <a href="{{ $directoryUrl }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-night px-6 text-sm font-bold text-white transition hover:bg-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                View All Brands @if($realBrands)<span class="font-semibold text-white/60">({{ number_format($realBrands) }})</span>@endif <x-plat.icon name="arrow-right" class="w-4 h-4" />
            </a>
        </div>
    </div>
</section>
