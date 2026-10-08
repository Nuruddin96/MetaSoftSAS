@php $lead = $entrepreneurs[0]; $rest = array_slice($entrepreneurs, 1); @endphp
<section id="entrepreneurs" class="scroll-mt-20 bg-cream py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="Entrepreneur spotlight" bn="উদ্যোক্তা"
            title="Meet the entrepreneurs."
            sub="The founders, makers and builders behind Bangladesh’s most loved brands."
            link="All entrepreneurs" href="{{ url('/') }}?browse=entrepreneurs#results" />

        <div class="mt-10 grid gap-5 lg:grid-cols-2">
            {{-- Editorial feature --}}
            <article class="grid overflow-hidden rounded-3xl bg-night text-white sm:grid-cols-[0.85fr_1fr]">
                <div class="relative min-h-[240px] overflow-hidden" style="background:linear-gradient(160deg,{{ $lead['from'] }},{{ $lead['to'] }})" aria-hidden="true">
                    @if(! empty($lead['cover']))
                        <img src="{{ $lead['cover'] }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                        <span class="absolute inset-0 bg-gradient-to-t from-night/70 via-night/10 to-transparent"></span>
                        <span class="absolute bottom-4 left-4 flex items-center gap-2.5">
                            <img src="{{ $lead['brand_logo'] }}" alt="" width="44" height="44" class="h-11 w-11 rounded-xl ring-2 ring-white/80">
                            <span class="text-sm font-bold text-white">{{ $lead['brand'] }}</span>
                        </span>
                    @else
                        <span class="absolute left-1/2 top-[22%] h-24 w-24 -translate-x-1/2 rounded-full bg-white/20"></span>
                        <span class="absolute -bottom-20 left-1/2 h-56 w-56 -translate-x-1/2 rounded-full bg-white/15"></span>
                        <span class="absolute bottom-4 left-4 font-plat text-6xl font-extrabold text-white/25">{{ $lead['initials'] }}</span>
                    @endif
                </div>
                <div class="flex flex-col p-6 sm:p-8">
                    <x-plat.badge :type="$lead['recognition']['type']" :label="$lead['recognition']['label']" size="xs" class="self-start" />
                    <p class="mt-4 text-5xl font-extrabold leading-none text-gold" aria-hidden="true">“</p>
                    <blockquote class="text-lg font-medium italic leading-relaxed sm:text-[19px]">{{ $lead['story'] }}</blockquote>
                    <div class="mt-auto pt-6">
                        <p class="flex items-center gap-1.5 text-lg font-extrabold">{{ $lead['name'] }} <x-plat.verified /></p>
                        <p class="text-[13px] text-white/60">{{ $lead['role'] }}, {{ $lead['brand'] }} · {{ $lead['category'] }} · {{ $lead['district'] }}</p>
                        <div class="mt-3 flex gap-4">
                            <button type="button" data-profile="person:{{ $lead['slug'] }}" class="text-sm font-bold text-gold hover:underline underline-offset-4">View profile →</button>
                            <a href="#stories" class="text-sm font-bold text-white/80 hover:text-white">Read the story</a>
                        </div>
                    </div>
                </div>
            </article>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach($rest as $person)
                    <x-plat.person-card :person="$person" />
                @endforeach
            </div>
        </div>
    </div>
</section>
