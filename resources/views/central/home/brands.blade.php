{{-- Browse by category + the Editor's pick / Sponsored legend. The brand rows themselves are in our-brands.blade.php (right after the hero). --}}
<section id="brand-categories" class="scroll-mt-20 bg-cloud py-12 sm:py-16">
    <div class="{{ $wrap }}">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold">Browse by category</h3>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($categories as $c)
                <a href="{{ url('/') }}?q={{ urlencode(explode(' ', $c['name'])[0]) }}#results" class="group flex items-center gap-3 rounded-2xl border border-hair bg-white p-3.5 transition hover:border-leaf hover:shadow-sm">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-mint text-leaf transition group-hover:bg-leaf group-hover:text-white"><x-plat.icon :name="$c['icon']" class="w-5 h-5" /></span>
                    <span class="min-w-0"><span class="block text-[13px] font-bold leading-tight">{{ $c['name'] }}</span><span class="block truncate font-body text-xs text-slate2" lang="bn">{{ $c['bn'] }}</span></span>
                </a>
            @endforeach
        </div>

        <p class="mt-6 flex items-start gap-2 text-[13px] text-slate2">
            <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0" />
            <span><b class="text-night">Editor’s Pick</b> is editorial. Sponsored placements are marked “Ad” and never affect awards or votes. The blue badge <x-plat.verified class="inline -mt-0.5" /> identifies admin-verified brands.</span>
        </p>
    </div>
</section>
