@php $rising = $heroRising; /* decorative hero mock-up (sample) */ @endphp
<section class="relative isolate overflow-hidden bg-night text-white">
    {{-- Decorative: navy-to-blue field (MetaSoft BD palette), kept quiet on purpose --}}
    <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[linear-gradient(135deg,#052B4C_0%,#073B66_55%,#1769AA_100%)]"></div>
        <div class="absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full border-[56px] border-white/[0.04]"></div>
    </div>

    <div class="{{ $wrap }} grid items-center gap-12 pb-8 pt-5 sm:py-16 lg:grid-cols-[1.1fr_0.9fr] lg:gap-10 lg:py-20">
        <div class="min-w-0">
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

        {{-- Platform composition: a live profile, a live vote and recognition chips --}}
        <div class="relative mx-auto hidden h-[560px] w-full max-w-[520px] lg:block" aria-hidden="true">
            <div class="absolute left-12 top-10 w-[380px] overflow-hidden rounded-3xl bg-white text-night shadow-2xl shadow-black/40">
                <div class="relative h-36 overflow-hidden" style="background:linear-gradient(135deg,#073B66,#1769AA)">
                    @if(! empty($heroBrand['cover']))
                        <img src="{{ $heroBrand['cover'] }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                    @else
                        <span class="absolute -right-8 -top-10 h-44 w-44 rounded-full bg-white/10"></span>
                    @endif
                    <span class="absolute left-3 top-3"><x-plat.badge type="editor" label="Editor’s pick" size="xs" /></span>
                </div>
                <div class="relative -mt-8 px-6 pb-6">
                    <div class="flex items-start gap-3">
                        <x-plat.logo :initials="$heroBrand['initials']" :from="$heroBrand['from']" :to="$heroBrand['to']" :size="64" ring :src="$heroBrand['logo'] ?? null" :alt="$heroBrand['name'].' logo'" />
                        <div class="pt-9">
                            <p class="flex items-center gap-1.5 text-lg font-extrabold">{{ $heroBrand['name'] }} @if($heroBrand['verified'] ?? false)<x-plat.verified />@endif</p>
                            <p class="text-[13px] text-slate2">{{ $heroBrand['category'] }} · {{ $heroBrand['district'] }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-slate2">{{ $heroBrand['description'] }}</p>
                    @if($heroBrand['badges'])
                        <div class="mt-3 flex gap-1.5">@foreach($heroBrand['badges'] as $b)<x-plat.badge :type="$b['type']" :label="$b['label']" size="xs" />@endforeach</div>
                    @endif
                    <div class="mt-4 flex gap-5 text-[13px]">@if($heroBrand['rating'])<b>★ {{ $heroBrand['rating'] }}</b>@endif @if($heroBrand['followers'])<span class="text-slate2">{{ $heroBrand['followers'] }} followers</span>@endif<span class="text-slate2">metasoftbd.com/{{ $heroBrand['slug'] }}</span></div>
                </div>
            </div>

            <div class="absolute bottom-6 left-0 w-[270px] rounded-2xl bg-white p-4 text-night shadow-2xl shadow-black/40">
                <p class="flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider text-leaf"><span class="h-2 w-2 rounded-full bg-leaf"></span> Showcase brands</p>
                {{-- Decorative and sample: brand names only — no vote counts, shares or voting call-to-action. --}}
                <ul class="mt-3 space-y-2">
                    @foreach(array_slice($rising['nominees'], 0, 3) as $n)
                        <li class="flex items-center gap-2 text-[13px] font-semibold"><x-plat.logo :initials="$n['brand']['initials']" :from="$n['brand']['from']" :to="$n['brand']['to']" :size="24" :src="$n['brand']['logo'] ?? null" alt="" />{{ $n['brand']['name'] }}</li>
                    @endforeach
                </ul>
                <div class="mt-4 rounded-[10px] bg-leaf py-2 text-center text-[13px] font-bold text-white">View profiles</div>
            </div>

            <div class="absolute right-0 top-0 flex items-center gap-3 rounded-full bg-white py-2.5 pl-2.5 pr-4 text-night shadow-2xl shadow-black/40">
                @php $chip = \App\Support\Home\Showcase::brand('respite-care'); @endphp
                <x-plat.logo :initials="$chip['initials']" :from="$chip['from']" :to="$chip['to']" :size="40" round :src="$chip['logo']" :alt="$chip['name'].' logo'" />
                <div><p class="text-sm font-bold">{{ $chip['name'] }}</p><p class="text-xs text-slate2">{{ $chip['category'] }}</p></div>
                <span class="rounded-full bg-navy px-2 py-0.5 text-[10px] font-bold text-white">Showcase</span>
            </div>

            <div class="absolute bottom-20 right-0 flex items-center gap-3 rounded-2xl bg-night/95 px-4 py-3 shadow-2xl ring-1 ring-white/10">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-gold to-golddk"><x-plat.icon name="trophy" class="w-4 h-4" /></span>
                <div><p class="text-[13px] font-bold">One Nation. One Stage.</p><p class="text-[11px] text-white/60">National awards · same rules</p></div>
            </div>
        </div>
    </div>
</section>
