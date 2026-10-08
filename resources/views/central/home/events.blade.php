<section id="events" class="scroll-mt-20 bg-cloud py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="Upcoming events" bn="ইভেন্ট"
            title="Meet the network in person."
            sub="Seminars, meetups, expos and the national awards night — across Bangladesh." />

        <div class="-mx-4 mt-10 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-5 sm:overflow-visible sm:px-0 lg:grid-cols-4">
            @foreach($events as $e)
                <article class="flex w-[78%] shrink-0 snap-start flex-col overflow-hidden rounded-[20px] border border-hair bg-white sm:w-auto">
                    <div class="relative h-28" style="background:linear-gradient(135deg,{{ $e['from'] }},{{ $e['to'] }})">
                        <div class="absolute left-4 top-4 w-14 rounded-xl bg-white py-1.5 text-center shadow-sm">
                            <span class="block text-xl font-extrabold leading-none text-night">{{ $e['day'] }}</span>
                            <span class="text-[10px] font-extrabold tracking-[0.14em] text-flag">{{ $e['month'] }}</span>
                        </div>
                        <span class="absolute right-4 top-4 rounded-full bg-night/50 px-2.5 py-1 text-[11px] font-semibold text-white backdrop-blur">{{ $e['type'] }}</span>
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <h3 class="text-[17px] font-extrabold leading-snug">{{ $e['title'] }}</h3>
                        <p class="mt-2.5 flex items-center gap-1.5 text-[13px] text-slate2"><x-plat.icon name="map-pin" class="w-4 h-4 shrink-0" /> {{ $e['place'] }}</p>
                        <p class="mt-1 flex items-center gap-1.5 text-[13px] text-slate2"><x-plat.icon name="clock" class="w-4 h-4 shrink-0" /> {{ $e['time'] }}</p>
                        <div class="mt-auto flex items-center justify-between pt-5">
                            <span><span class="block text-base font-extrabold {{ $e['price'] === 'Free' ? 'text-leaf' : '' }}">{{ $e['price'] }}</span><span class="text-[11px] text-slate2">{{ $e['going'] }}</span></span>
                            <a href="{{ $wa('I want to join: '.$e['title'].' ('.$e['day'].' '.$e['month'].')') }}" target="_blank" rel="noopener"
                               class="rounded-[10px] px-3.5 py-2 text-[13px] font-bold {{ ! empty($e['gold']) ? 'bg-gold text-night' : 'bg-night text-white hover:bg-navy' }}">{{ $e['cta'] }}</a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
