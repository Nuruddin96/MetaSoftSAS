<section class="relative isolate overflow-hidden bg-night text-white">
    {{-- Decorative: navy-to-blue field (MetaSoft BD palette), kept quiet on purpose --}}
    <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[linear-gradient(135deg,#052B4C_0%,#073B66_55%,#1769AA_100%)]"></div>
        <div class="absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full border-[56px] border-white/[0.04]"></div>
        {{-- Faint dot field behind the glass map, so the glass visibly shows what is behind it --}}
        <div class="bd-dots absolute inset-0 hidden sm:block"></div>
    </div>

    <div class="{{ $wrap }} grid items-center gap-12 pb-8 pt-5 sm:py-16 lg:grid-cols-[1.1fr_0.9fr] lg:gap-10 lg:py-20">
        <div class="relative z-10 min-w-0">
            <a href="#voting" class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.06] px-3.5 py-1.5 text-[13px] font-medium text-white/90 transition hover:bg-white/10">
                <span @class(['h-2 w-2 rounded-full', 'bg-flag' => $votingOpen, 'bg-gold' => ! $votingOpen])></span> {{ $home['hero_pill'] }}
                <x-plat.icon name="chevron-right" class="w-3.5 h-3.5 text-white/60" />
            </a>

            {{-- Phones: two lines (title / highlight), sized to the viewport so the first line fits in one row. --}}
            <h1 class="mt-3 text-[length:clamp(16px,calc((100vw_-_32px)/18.5),30px)] font-extrabold leading-[1.2] tracking-[-0.03em] sm:mt-6 sm:text-[42px] sm:leading-[1.08] lg:text-[48px]">
                {{ $home['hero_title'] }} <span class="block text-gold sm:inline">{{ $home['hero_highlight'] }}</span>
            </h1>
            <p class="mt-2 font-body text-base font-medium text-sky-200 sm:mt-4 sm:text-xl" lang="bn">বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম</p>
            <p class="mt-2 max-w-xl text-[14.5px] leading-relaxed text-white/75 sm:mt-4 sm:text-[17px]">
                {{ $home['hero_sub'] }}
            </p>

            <form action="{{ url('/') }}#results" method="get" role="search" class="mt-4 flex max-w-xl items-center gap-2 rounded-2xl bg-white p-1 shadow-2xl shadow-black/25 sm:mt-7 sm:p-2">
                <x-plat.icon name="search" class="ml-2.5 w-5 h-5 shrink-0 text-slate2" />
                <label for="heroSearch" class="sr-only">Search brands, entrepreneurs, products</label>
                <input id="heroSearch" name="q" type="search" value="{{ $q }}" autocomplete="off"
                       placeholder="Search brands, entrepreneurs, products…"
                       class="h-9 min-w-0 flex-1 bg-transparent text-base text-night outline-none placeholder:text-slate2 sm:h-11 sm:text-[15px]">
                <button type="submit" class="shrink-0 rounded-xl bg-leaf px-4 py-2 text-sm font-bold text-white transition hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 sm:px-6 sm:py-3">Search</button>
            </form>
            <div class="-mx-4 mt-2.5 flex items-center gap-2 overflow-x-auto px-4 text-[13px] [scrollbar-width:none] sm:mx-0 sm:mt-3 sm:flex-wrap sm:px-0">
                <span class="shrink-0 text-white/55">Popular:</span>
                @foreach(['Fashion', 'Food', 'Handicrafts', 'Tech', 'Dhaka', 'Sylhet'] as $chip)
                    <a href="{{ url('/') }}?q={{ urlencode($chip) }}#results" class="shrink-0 rounded-full bg-white/[0.08] px-3 py-1 font-medium text-white/85 transition hover:bg-white/15">{{ $chip }}</a>
                @endforeach
            </div>

            <div class="mt-5 flex flex-col gap-2.5 sm:mt-7 sm:flex-row sm:gap-3">
                <a href="#brands" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-6 py-3 text-[15px] sm:py-3.5 font-bold text-night transition hover:brightness-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-night">
                    Explore brands <x-plat.icon name="arrow-right" class="w-4 h-4" />
                </a>
                <a href="#join" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/30 px-6 py-3 text-[15px] sm:py-3.5 font-bold text-white transition hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    List your brand free
                </a>
            </div>

            {{-- Real counts only (HomepageContent::stats); hidden until there are some. --}}
            @if($stats)
            <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-4 border-t border-white/10 pt-5 sm:mt-9 sm:grid-cols-4 sm:gap-y-5 sm:pt-7">
                @foreach($stats as $s)
                    <div>
                        <dt class="sr-only">{{ $s['label'] }}</dt>
                        <dd class="text-2xl font-extrabold tracking-tight">{{ $s['value'] }}</dd>
                        <dd class="text-[13px] text-white/60">{{ $s['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
            @endif
        </div>

        {{-- Glass map of Bangladesh: routes carry a pulse from Dhaka; each pointer flashes red twice on arrival.
             Desktop: right column. Tablet: centred below the copy. Phone: faded backdrop behind the copy. --}}
        <x-plat.bd-network />
    </div>
</section>
