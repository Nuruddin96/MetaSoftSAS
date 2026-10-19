@php
    // The highlight line stays on one line while it fits at ≥17px: its font size follows the text column
    // (.hero-fit, container units), scaled by the text's length (~0.53em per character at this weight).
    // Unusually long highlights from Super Admin → Homepage simply wrap instead.
    $highlightFit = round(max(mb_strlen($home['hero_highlight']), 12) * 0.53, 2);
    $highlightOneLine = mb_strlen($home['hero_highlight']) <= 36;
    $tiles = [
        ['shield', 'Verified', '#verified', false],
        ['user-plus', 'Create Profile', $joinUrl, false],
        ['trophy', 'Free Award', '#awards', false],
        ['search', 'Find Brands', '#heroSearch', true],
    ];
@endphp
<section @class(["relative isolate overflow-hidden bg-night text-white", "pb-8 sm:pb-12" => $media["moments"]])>
    {{-- Decorative: navy-to-blue field (MetaSoft BD palette), kept quiet on purpose --}}
    <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[linear-gradient(135deg,#052B4C_0%,#073B66_55%,#1769AA_100%)]"></div>
        <div class="absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full border-[56px] border-white/[0.04]"></div>
        {{-- Faint dot field behind the glass map, so the glass visibly shows what is behind it --}}
        <div class="bd-dots absolute inset-0 hidden sm:block"></div>
    </div>

    <div class="{{ $wrap }} grid items-center gap-12 pb-7 pt-5 sm:py-12 lg:grid-cols-[1.15fr_0.85fr] lg:gap-10 lg:py-14">
        <div class="hero-fit relative z-10 min-w-0">
            <a href="#awards" class="inline-flex max-w-full items-center gap-2 rounded-full border border-white/15 bg-white/[0.06] px-2 py-1 text-[10.5px] font-medium leading-5 tracking-[-0.01em] text-white/90 transition hover:bg-white/10 min-[380px]:px-3 min-[380px]:text-[11.5px] min-[400px]:text-[12px] sm:text-[13px]">
                <span class="h-2 w-2 shrink-0 rounded-full bg-flag ring-2 ring-flag/25"></span> <span class="min-w-0">{{ $home['hero_pill'] }}</span>
            </a>

            <h1 class="hero-title mt-3 font-extrabold leading-[1.12] tracking-[-0.025em] sm:mt-5" style="--k: {{ $highlightFit }}">
                {{ $home['hero_title'] }} <span @class(['text-gold', 'min-[340px]:block min-[340px]:whitespace-nowrap' => $highlightOneLine])>{{ $home['hero_highlight'] }}</span>
            </h1>
            <p class="mt-2 font-body text-[14px] font-medium text-sky-200 min-[380px]:text-[15px] sm:mt-3 sm:text-lg" lang="bn">বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম</p>

            {{-- Primary action + the existing server-side search (?q=…#results). --}}
            <div class="mt-3.5 flex flex-col gap-2 sm:mt-6 sm:flex-row sm:gap-3">
                <a href="{{ $joinUrl }}" class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-gold px-5 sm:h-12 text-[15px] font-extrabold text-night transition hover:brightness-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-night">
                    <x-plat.icon name="user-plus" class="w-[18px] h-[18px]" /> Create Free Business Profile
                </a>
                <form action="{{ url('/') }}#results" method="get" role="search" class="min-w-0 sm:max-w-[400px] sm:flex-1">
                    <div class="flex h-11 items-center gap-2 rounded-xl bg-white pl-3.5 pr-1 sm:h-12 shadow-[0_10px_24px_-14px_rgba(0,0,0,0.6)] ring-1 ring-white/40 focus-within:ring-2 focus-within:ring-gold">
                        <x-plat.icon name="search" class="w-[18px] h-[18px] shrink-0 text-golddk" />
                        <label for="heroSearch" class="sr-only">Search brands and entrepreneurs</label>
                        <input id="heroSearch" name="q" type="search" value="{{ $q }}" autocomplete="off" maxlength="80"
                               placeholder="Brands & entrepreneurs…"
                               class="h-full min-w-0 flex-1 bg-transparent text-base text-night outline-none placeholder:text-[14.5px] placeholder:text-slate2 sm:text-[15px]">
                        <button type="submit" class="inline-flex h-9 shrink-0 items-center justify-center rounded-lg bg-night px-3.5 text-[14px] font-bold text-white transition hover:bg-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold sm:h-10">Search</button>
                    </div>
                </form>
            </div>

            {{-- Four glass action tiles in one row at every width: icon above label on phones, side by side from 640px. --}}
            <nav class="mt-3 grid grid-cols-4 gap-1.5 sm:mt-5 sm:max-w-[600px] sm:gap-2.5" aria-label="Quick actions">
                @foreach($tiles as [$icon, $label, $href, $focus])
                    <a href="{{ $href }}" @if($focus) data-focus="{{ $href }}" @endif class="glass-tile flex min-w-0 flex-col items-center justify-center gap-0.5 rounded-xl px-1 py-1 text-center sm:min-h-12 sm:flex-row sm:justify-start sm:gap-2 sm:px-2.5 sm:py-2 sm:text-left">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-gold sm:h-8 sm:w-8 sm:bg-white/10 sm:ring-1 sm:ring-white/15"><x-plat.icon :name="$icon" class="w-[18px] h-[18px] sm:w-[17px] sm:h-[17px]" /></span>
                        <span class="min-w-0 text-[11.5px] font-bold leading-[1.15] text-white min-[380px]:text-[12px] sm:text-[13.5px] sm:leading-tight">{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        {{-- Glass map of Bangladesh: routes carry a pulse from Dhaka; each pointer flashes red twice on arrival.
             Desktop: right column. Tablet: centred below the copy. Phone: faded backdrop, centred and fully inside the hero. --}}
        <x-plat.bd-network />
    </div>
</section>
