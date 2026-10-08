@php $lead = $stories['lead']; @endphp
<section id="stories" class="scroll-mt-20 border-t border-hair bg-white py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="MetaSoft BD Media" bn="গল্প"
            title="Stories behind the brands."
            sub="Founder stories, brand journeys and interviews — in Bangla and English." />

        <div class="-mx-4 mt-8 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0">
            <div class="flex w-max gap-2">
                @foreach($stories['tags'] as $t)
                    <span class="whitespace-nowrap rounded-full px-4 py-2 text-[13px] font-semibold {{ $loop->first ? 'bg-night text-white' : 'bg-cloud text-night' }}">{{ $t }}</span>
                @endforeach
            </div>
        </div>

        <div class="mt-8 grid gap-8 lg:grid-cols-[1.15fr_1fr] lg:gap-12">
            <article class="group">
                <div class="relative aspect-[16/9] overflow-hidden rounded-3xl" style="background:linear-gradient(135deg,{{ $lead['from'] }},{{ $lead['to'] }})">
                    <span aria-hidden="true" class="absolute -right-10 -top-16 h-72 w-72 rounded-full bg-white/10 transition duration-500 group-hover:scale-110"></span>
                    <span class="absolute left-4 top-4 rounded-full bg-white px-3 py-1 text-xs font-bold text-night">{{ $lead['tag'] }}</span>
                    <span class="absolute bottom-4 left-4 inline-flex items-center gap-2 rounded-full bg-night/70 px-3.5 py-2 text-xs font-bold text-white backdrop-blur"><x-plat.icon name="play" class="w-3.5 h-3.5" /> Watch · {{ $lead['video'] }}</span>
                </div>
                <h3 class="mt-5 text-2xl font-extrabold leading-tight tracking-tight sm:text-[28px]">{{ $lead['title'] }}</h3>
                <p class="mt-3 text-[15px] leading-relaxed text-slate2">{{ $lead['excerpt'] }}</p>
                <p class="mt-4 flex items-center gap-2 text-[13px] text-slate2">
                    <x-plat.logo initials="RK" from="#0EA5E9" to="#1E3A8A" :size="28" round />
                    <b class="text-night">{{ $lead['author'] }}</b> · {{ $lead['meta'] }}
                </p>
            </article>

            <ul class="divide-y divide-hair">
                @foreach($stories['list'] as $s)
                    <li class="flex gap-4 py-4 first:pt-0 sm:gap-5">
                        <span class="block aspect-[4/3] w-28 shrink-0 overflow-hidden rounded-2xl sm:w-36" style="background:linear-gradient(135deg,{{ $s['from'] }},{{ $s['to'] }})" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-extrabold uppercase tracking-[0.12em] text-leaf">{{ $s['tag'] }}</p>
                            <h3 @class(['mt-1.5 font-bold leading-snug', 'font-body text-lg' => $s['bn'], 'text-base sm:text-[17px]' => ! $s['bn']]) @if($s['bn']) lang="bn" @endif>{{ $s['title'] }}</h3>
                            <p @class(['mt-1.5 text-xs text-slate2', 'font-body' => $s['bn']])>{{ $s['meta'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
