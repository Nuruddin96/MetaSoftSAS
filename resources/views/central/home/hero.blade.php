@php $rising = $heroRising; /* decorative hero mock-up (sample) */ @endphp
<section class="relative isolate overflow-hidden bg-night text-white">
    {{-- Decorative: deep green field + soft red sun, a quiet nod to the flag --}}
    <div aria-hidden="true" class="absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-[linear-gradient(135deg,#0A1428_0%,#0E2350_55%,#00513C_100%)]"></div>
        <div class="bg-glow absolute -right-24 top-10 h-[30rem] w-[30rem] bg-flag/25"></div>
        <div class="bg-glow absolute -bottom-40 -left-24 h-[28rem] w-[28rem] bg-emerald-500/20" style="animation-delay:-8s"></div>
        <div class="absolute inset-0 opacity-[0.07]" style="background-image:radial-gradient(#fff 1px,transparent 1px);background-size:22px 22px"></div>
    </div>

    <div class="{{ $wrap }} grid items-center gap-12 py-12 sm:py-16 lg:grid-cols-[1.1fr_0.9fr] lg:gap-10 lg:py-20">
        <div>
            <a href="#voting" class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.06] px-3.5 py-1.5 text-[13px] font-medium text-white/90 transition hover:bg-white/10">
                <span class="h-2 w-2 rounded-full bg-flag"></span> {{ $home['hero_pill'] }}
                <x-plat.icon name="chevron-right" class="w-3.5 h-3.5 text-white/60" />
            </a>

            <h1 class="mt-6 text-[38px] font-extrabold leading-[1.06] tracking-[-0.03em] sm:text-5xl lg:text-[58px]">
                {{ $home['hero_title'] }} <span class="text-gold">{{ $home['hero_highlight'] }}</span>
            </h1>
            <p class="mt-4 font-body text-lg font-medium text-emerald-200 sm:text-xl" lang="bn">বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম</p>
            <p class="mt-4 max-w-xl text-base leading-relaxed text-white/75 sm:text-[17px]">
                {{ $home['hero_sub'] }}
            </p>

            <form action="{{ url('/') }}#results" method="get" role="search" class="mt-7 flex max-w-xl items-center gap-2 rounded-2xl bg-white p-2 shadow-2xl shadow-black/25">
                <x-plat.icon name="search" class="ml-2.5 w-5 h-5 shrink-0 text-slate2" />
                <label for="heroSearch" class="sr-only">Search brands, entrepreneurs, products</label>
                <input id="heroSearch" name="q" type="search" value="{{ $q }}" autocomplete="off"
                       placeholder="Search brands, entrepreneurs, products…"
                       class="h-11 min-w-0 flex-1 bg-transparent text-[15px] text-night outline-none placeholder:text-slate2">
                <button type="submit" class="shrink-0 rounded-xl bg-leaf px-4 py-3 text-sm font-bold text-white transition hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2 sm:px-6">Search</button>
            </form>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-[13px]">
                <span class="text-white/55">Popular:</span>
                @foreach(['Fashion', 'Food', 'Handicrafts', 'Tech', 'Dhaka', 'Sylhet'] as $chip)
                    <a href="{{ url('/') }}?q={{ urlencode($chip) }}#results" class="rounded-full bg-white/[0.08] px-3 py-1 font-medium text-white/85 transition hover:bg-white/15">{{ $chip }}</a>
                @endforeach
            </div>

            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <a href="#brands" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-6 py-3.5 text-[15px] font-bold text-night transition hover:brightness-105 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-night">
                    Explore brands <x-plat.icon name="arrow-right" class="w-4 h-4" />
                </a>
                <a href="#join" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/30 px-6 py-3.5 text-[15px] font-bold text-white transition hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
                    Join / list your brand — free
                </a>
            </div>

            <dl class="mt-9 grid grid-cols-2 gap-x-6 gap-y-5 border-t border-white/10 pt-7 sm:grid-cols-4">
                @foreach($stats as $s)
                    <div>
                        <dt class="sr-only">{{ $s['label'] }}</dt>
                        <dd class="text-2xl font-extrabold tracking-tight">{{ $s['value'] }}</dd>
                        <dd class="text-[13px] text-white/60">{{ $s['label'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Platform composition: a live profile, a live vote and recognition chips --}}
        <div class="relative mx-auto hidden h-[560px] w-full max-w-[520px] lg:block" aria-hidden="true">
            <div class="absolute left-12 top-10 w-[380px] overflow-hidden rounded-3xl bg-white text-night shadow-2xl shadow-black/40">
                <div class="relative h-36 overflow-hidden" style="background:linear-gradient(135deg,#C2410C,#7C2D12)">
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
                            <p class="flex items-center gap-1.5 text-lg font-extrabold">{{ $heroBrand['name'] }} <x-plat.verified /></p>
                            <p class="text-[13px] text-slate2">{{ $heroBrand['category'] }} · {{ $heroBrand['district'] }}</p>
                        </div>
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-slate2">{{ $heroBrand['description'] }}</p>
                    <div class="mt-3 flex gap-1.5"><x-plat.badge type="people" label="People’s Choice 2025" size="xs" /><x-plat.badge type="finalist" label="Women-led" size="xs" /></div>
                    <div class="mt-4 flex gap-5 text-[13px]"><b>★ {{ $heroBrand['rating'] }}</b><span class="text-slate2">{{ $heroBrand['followers'] }} followers</span><span class="text-slate2">metasoftbd.com/{{ $heroBrand['slug'] }}</span></div>
                </div>
            </div>

            <div class="absolute bottom-6 left-0 w-[270px] rounded-2xl bg-white p-4 text-night shadow-2xl shadow-black/40">
                <p class="flex items-center gap-1.5 text-[11px] font-extrabold uppercase tracking-wider text-flag"><span class="h-2 w-2 rounded-full bg-flag"></span> Live · Rising Brand 2026</p>
                <div class="mt-3 space-y-3">
                    @foreach(array_slice($rising['nominees'], 0, 3) as $i => $n)
                        <div>
                            <div class="flex justify-between text-[13px]"><span class="font-semibold">{{ $n['brand']['name'] }}</span><b>{{ $n['pct'] }}%</b></div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-cloud"><div class="h-full rounded-full {{ ['bg-leaf', 'bg-sky-500', 'bg-gold'][$i] }}" style="width:{{ $n['bar'] }}%"></div></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-[10px] bg-leaf py-2 text-center text-[13px] font-bold text-white">Vote now</div>
            </div>

            <div class="absolute right-0 top-0 flex items-center gap-3 rounded-full bg-white py-2.5 pl-2.5 pr-4 text-night shadow-2xl shadow-black/40">
                <x-plat.logo initials="TA" from="#0EA5E9" to="#1E3A8A" :size="40" round :src="\App\Support\Home\Showcase::art('logos', 'krishi-bondhu')" alt="Krishi Bondhu logo" />
                <div><p class="text-sm font-bold">Tanvir Ahmed</p><p class="text-xs text-slate2">Founder, Krishi Bondhu</p></div>
                <span class="rounded-full bg-navy px-2 py-0.5 text-[10px] font-bold text-white">Jury Pick</span>
            </div>

            <div class="absolute bottom-20 right-0 flex items-center gap-3 rounded-2xl bg-night/95 px-4 py-3 shadow-2xl ring-1 ring-white/10">
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-gold to-golddk"><x-plat.icon name="trophy" class="w-4 h-4" /></span>
                <div><p class="text-[13px] font-bold">District → Division → National</p><p class="text-[11px] text-white/60">64 districts · 8 divisions</p></div>
            </div>
        </div>
    </div>
</section>
