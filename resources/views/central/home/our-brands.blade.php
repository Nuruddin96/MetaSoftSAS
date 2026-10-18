{{--
    Our Brands — directly below the hero. HomepageContent::ourBrands():
    editorial picks and labelled sponsored placements first, then every
    other approved brand newest first. Exactly eight cards (2 columns on
    phones, 4 from 640px); when fewer brands exist, the remaining slots are
    open "Your brand here" invitations, never invented brands.
--}}
@php
    $directoryUrl = $realBrands ? route('brands.index') : url('/').'?browse=brands#results';
    $showcase = array_slice($ourBrands, 0, 8);
@endphp
<section id="brands" class="scroll-mt-20 bg-white py-8 sm:py-12 lg:py-14" aria-labelledby="ourBrandsTitle">
    <div class="{{ $wrap }}">
        <div class="flex items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-[0.16em] text-golddk"><span class="h-px w-6 bg-golddk"></span> Our Brands <span class="font-body text-[12.5px] font-semibold normal-case tracking-normal text-slate2" lang="bn">· আমাদের ব্র্যান্ড</span></p>
                <h2 id="ourBrandsTitle" class="mt-1.5 font-plat text-[22px] font-extrabold leading-tight tracking-tight text-night sm:text-[28px]">Brands building Bangladesh</h2>
                <p class="mt-1.5 text-[14.5px] text-slate2">Established businesses and first-time founders, side by side. Every profile is free.</p>
            </div>
            <a href="{{ $directoryUrl }}" class="hidden shrink-0 items-center gap-1 text-sm font-bold text-leaf hover:underline sm:inline-flex">View all <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
        </div>

        @if($showcase)
            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:gap-4">
                @foreach($showcase as $brand)
                    <x-plat.showcase-card :brand="$brand" />
                @endforeach
                @for($i = count($showcase); $i < 8; $i++)
                    {{-- Open slot: not a brand, an invitation to list one. --}}
                    <a href="{{ $joinUrl }}" class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-dashed border-[#9FB3CB] bg-cloud transition hover:border-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                        <span class="flex h-[72px] items-center justify-center bg-[repeating-linear-gradient(135deg,#EEF3F9_0_10px,#E6EDF6_10px_20px)] sm:h-24"><span class="rounded bg-white px-1.5 text-[10.5px] font-bold uppercase leading-[18px] tracking-wide text-slate2">Open slot</span></span>
                        <span class="block px-3 pb-3 sm:px-3.5 sm:pb-3.5">
                            <span class="relative -mt-6 flex h-12 w-12 items-center justify-center rounded-[12px] bg-white text-golddk shadow-sm ring-[3px] ring-white"><x-plat.icon name="user-plus" class="w-5 h-5" /></span>
                            <span class="mt-1.5 block text-[14.5px] font-bold leading-snug text-night">Your brand here</span>
                            <span class="block truncate text-[12.5px] text-slate2">Create a free profile</span>
                        </span>
                    </a>
                @endfor
            </div>
        @else
            <div class="mt-4 rounded-2xl border border-dashed border-hair bg-cloud px-6 py-10 text-center">
                <p class="font-bold">The first brands are being reviewed right now.</p>
                <a href="{{ $joinUrl }}" class="mt-3 inline-flex text-sm font-bold text-leaf hover:underline">List your brand — free</a>
            </div>
        @endif

        <div class="mt-5 flex justify-center sm:mt-8">
            <a href="{{ $directoryUrl }}" class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-night px-6 text-[15px] font-bold text-white transition hover:bg-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 sm:w-auto">
                View All Brands @if($realBrands)<span class="font-semibold text-white/60">({{ number_format($realBrands) }})</span>@endif <x-plat.icon name="arrow-right" class="w-4 h-4" />
            </a>
        </div>
        <p class="mt-3 text-center text-[12.5px] text-slate2">Only brands checked by MetaSoft BD carry the blue Verified badge.</p>
    </div>
</section>
