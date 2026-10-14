<section id="events" class="scroll-mt-20 bg-cloud py-12 sm:py-16 lg:py-20">
    <div class="{{ $wrap }}">
        {{-- No events table exists yet: these are example formats only — no dates, venues, prices, attendance or sign-up for events that aren't scheduled. --}}
        <x-plat.section-head eyebrow="Events" bn="ইভেন্ট" sample
            title="Meet the network in person."
            sub="Example event formats. Real dates and venues will be announced here." />

        <div class="-mx-4 mt-8 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:gap-5 sm:overflow-visible sm:px-0 lg:grid-cols-4">
            @foreach($events as $e)
                <article class="flex w-[78%] shrink-0 snap-start flex-col overflow-hidden rounded-[20px] border border-hair bg-white sm:w-auto">
                    <div class="relative h-28" style="background:linear-gradient(135deg,{{ $e['from'] }},{{ $e['to'] }})">
                        <span class="absolute left-4 top-4 rounded-full bg-white px-2.5 py-1 text-[11px] font-bold text-slate2">Example</span>
                        <span class="absolute right-4 top-4 rounded-full bg-night/50 px-2.5 py-1 text-[11px] font-semibold text-white backdrop-blur">{{ $e['type'] }}</span>
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <h3 class="text-[17px] font-extrabold leading-snug">{{ $e['title'] }}</h3>
                        <p class="mt-2.5 flex items-center gap-1.5 text-[13px] text-slate2"><x-plat.icon name="clock" class="w-4 h-4 shrink-0" /> Date and venue to be announced</p>
                        <div class="mt-auto pt-4">
                            <a href="{{ $wa('Please let me know when MetaSoft BD announces: '.$e['type']) }}" target="_blank" rel="noopener"
                               class="inline-flex items-center justify-center gap-1.5 rounded-[10px] border border-hair px-3.5 py-2 text-[13px] font-bold text-night transition hover:border-leaf hover:text-leaf">Get notified</a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
