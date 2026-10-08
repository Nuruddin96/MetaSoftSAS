<section id="brands" class="scroll-mt-20 bg-cloud py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        {{-- Manual: editor's picks + labelled sponsored placements (Super Admin → Brands) --}}
        <x-plat.section-head eyebrow="Featured brands" bn="ফিচার্ড ব্র্যান্ড"
            title="Brands worth knowing."
            sub="Hand-picked by the MetaSoft BD editorial team. Sponsored placements are always labelled."
            link="Browse all brands" href="{{ url('/') }}?browse=brands#results" />

        <div class="-mx-4 mt-10 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-5 sm:overflow-visible sm:px-0 lg:grid-cols-4">
            @foreach($featured as $brand)
                <div class="w-[80%] shrink-0 snap-start sm:w-auto"><x-plat.brand-card :brand="$brand" /></div>
            @endforeach
        </div>

        {{-- Automatic: every approved brand, newest first — no featuring or payment needed --}}
        <div class="mt-12 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3 class="text-lg font-bold">Newly listed brands</h3>
                <p class="text-sm text-slate2">Every brand approved on MetaSoft BD appears here automatically.</p>
            </div>
            @if($realBrands)
                <a href="{{ route('brands.index') }}" class="inline-flex items-center gap-1 text-sm font-bold text-leaf hover:underline">Brand directory ({{ number_format($realBrands) }}) <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
            @else
                <a href="{{ route('owner.register') }}" class="inline-flex items-center gap-1 text-sm font-bold text-leaf hover:underline">List your brand <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
            @endif
        </div>
        <div class="-mx-4 mt-5 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-5 sm:overflow-visible sm:px-0 lg:grid-cols-4">
            @foreach($discover as $brand)
                <div class="w-[80%] shrink-0 snap-start sm:w-auto"><x-plat.brand-card :brand="$brand" /></div>
            @endforeach
        </div>

        <div class="mt-12 flex items-center justify-between">
            <h3 class="text-lg font-bold">Browse by category</h3>
            <span class="text-sm font-semibold text-slate2">38 categories</span>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($categories as $c)
                <a href="{{ url('/') }}?q={{ urlencode(explode(' ', $c['name'])[0]) }}#results" class="group flex items-center gap-3 rounded-2xl border border-hair bg-white p-3.5 transition hover:border-leaf hover:shadow-sm">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background-color: {{ $c['color'] }}1A; color: {{ $c['color'] }}"><x-plat.icon :name="$c['icon']" class="w-5 h-5" /></span>
                    <span class="min-w-0"><span class="block truncate text-[13px] font-bold">{{ $c['name'] }}</span><span class="block text-xs text-slate2">{{ $c['count'] }} brands</span></span>
                </a>
            @endforeach
        </div>

        <p class="mt-6 flex items-start gap-2 text-[13px] text-slate2">
            <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0" />
            <span><b class="text-night">Editor’s pick</b> is chosen by our editors and can’t be bought. <b class="text-night">Sponsored</b> marks a paid placement — it never affects awards, rankings or votes.</span>
        </p>
    </div>
</section>
