<section id="awards" class="scroll-mt-20 bg-cream py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="Live & current awards" bn="চলমান অ্যাওয়ার্ড"
            title="Recognition that is earned, not bought."
            sub="Every award runs on a published process: free nomination, phone-verified public voting and an independent jury."
            link="Award rules" href="#recognition" />

        <div class="mt-10 grid gap-5 lg:grid-cols-[1.45fr_1fr]">
            {{-- Featured award --}}
            <article class="relative overflow-hidden rounded-3xl bg-[linear-gradient(140deg,#0A1428,#1B2F5E)] p-6 text-white sm:p-9">
                <span aria-hidden="true" class="absolute -right-16 -top-16 h-64 w-64 rounded-full border-[28px] border-gold/10"></span>
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-flag px-2.5 py-1 text-xs font-bold"><span class="h-1.5 w-1.5 rounded-full bg-white"></span>{{ $award['status'] }}</span>
                        <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold">{{ $award['edition'] }}</span>
                    </div>
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-gold to-golddk shadow-lg shadow-black/30"><x-plat.icon name="trophy" class="w-7 h-7" /></span>
                </div>
                <h3 class="relative mt-5 max-w-lg text-[26px] font-extrabold leading-[1.15] tracking-tight sm:text-[34px]">{{ $award['title'] }}</h3>
                <p class="mt-2 font-body text-[17px] font-medium text-gold" lang="bn">{{ $award['bn'] }}</p>

                <dl class="mt-6 grid grid-cols-2 overflow-hidden rounded-2xl border border-white/10 sm:grid-cols-4">
                    @foreach($award['stats'] as $s)
                        <div class="border-white/10 px-4 py-3.5 [&:nth-child(odd)]:border-r sm:border-r sm:last:border-r-0 [&:nth-child(-n+2)]:border-b sm:[&:nth-child(-n+2)]:border-b-0">
                            <dd class="text-2xl font-extrabold">{{ $s['value'] }}</dd>
                            <dt class="text-xs text-white/60">{{ $s['label'] }}</dt>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-white/55">{{ $award['closes_title'] ?? 'Voting closes' }}</p>
                        <p class="font-bold">{{ $award['closes_label'] }}</p>
                    </div>
                    @if($award['closes_at'])
                        <div class="flex gap-2" data-countdown="{{ $award['closes_at'] }}" aria-label="Time left">
                            @foreach(['days', 'hrs', 'min', 'sec'] as $unit)
                                <div class="w-14 rounded-xl bg-white/[0.08] py-2 text-center">
                                    <span class="block text-xl font-extrabold tabular-nums" data-unit="{{ $unit }}">--</span>
                                    <span class="text-[10px] text-white/55">{{ $unit }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <ol class="mt-7 grid grid-cols-2 gap-3 sm:flex sm:items-center sm:gap-2" aria-label="Award process">
                    @foreach($award['steps'] as $i => $step)
                        <li class="flex items-center gap-2">
                            <span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                'bg-emerald-500' => $step['state'] === 'done',
                                'bg-gold text-night ring-4 ring-gold/25' => $step['state'] === 'current',
                                'bg-white/10' => $step['state'] === 'next'])>
                                @if($step['state'] === 'done')<x-plat.icon name="check" class="w-3.5 h-3.5" stroke="3" />@else{{ $i + 1 }}@endif
                            </span>
                            <span class="leading-tight"><span class="block text-[13px] font-bold">{{ $step['label'] }}</span><span class="block text-[11px] text-white/55">{{ $step['meta'] }}</span></span>
                            @if(! $loop->last)<span class="ml-1 hidden h-px w-6 bg-white/25 sm:block"></span>@endif
                        </li>
                    @endforeach
                </ol>

                <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                    <a href="#voting" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-6 py-3 text-[15px] font-bold text-night hover:brightness-105">Vote now <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    <a href="{{ ($award['live'] ?? false) ? $award['url'] : '#recognition' }}" class="inline-flex items-center justify-center rounded-xl border border-white/30 px-6 py-3 text-[15px] font-bold hover:bg-white/10">View categories & rules</a>
                </div>
            </article>

            {{-- Other awards --}}
            <div class="flex flex-col gap-4">
                @foreach($otherAwards as $a)
                    @php $tone = ['voting' => 'bg-rose-50 text-rose-700', 'nominate' => 'bg-mint text-leafdk', 'jury' => 'bg-indigo-50 text-indigo-700'][$a['state']]; @endphp
                    <article class="flex flex-1 items-center gap-4 rounded-2xl border border-hair bg-white p-5">
                        <x-plat.logo :initials="$a['initials']" :from="$a['from']" :to="$a['to']" :size="54" />
                        <div class="min-w-0 flex-1">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $tone }}"><span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $a['status'] }}</span>
                            <h3 class="mt-1.5 text-base font-bold leading-snug">{{ $a['title'] }}</h3>
                            <p class="mt-0.5 text-[13px] text-slate2">{{ $a['category'] }} · {{ $a['nominees'] }} nominees · {{ $a['when'] }}</p>
                        </div>
                        <a href="{{ $a['state'] === 'nominate' ? '#join' : '#voting' }}" class="hidden shrink-0 rounded-lg border border-hair px-3 py-2 text-[13px] font-bold hover:border-leaf hover:text-leaf sm:inline-flex">
                            {{ ['voting' => 'Vote', 'nominate' => 'Nominate', 'jury' => 'Follow'][$a['state']] }}
                        </a>
                    </article>
                @endforeach
            </div>
        </div>

        {{-- District → Division → National --}}
        <div class="mt-5 grid gap-6 rounded-3xl border border-hair bg-white p-6 sm:p-8 lg:grid-cols-[1fr_1.25fr] lg:items-center">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-leaf">Every brand can win somewhere</p>
                <h3 class="mt-2 text-2xl font-extrabold tracking-tight">District → Division → National</h3>
                <p class="mt-2 text-[15px] leading-relaxed text-slate2">Start in your district, rise to your division, compete for the national title. A small brand in Rangpur gets the same fair shot as a big one in Dhaka.</p>
                <ol class="mt-5 flex items-center gap-2 text-sm font-bold">
                    <li class="rounded-xl bg-mint px-3 py-2 text-leafdk">64 Districts</li>
                    <li aria-hidden="true"><x-plat.icon name="chevron-right" class="w-4 h-4 text-slate2" /></li>
                    <li class="rounded-xl bg-indigo-50 px-3 py-2 text-indigo-700">8 Divisions</li>
                    <li aria-hidden="true"><x-plat.icon name="chevron-right" class="w-4 h-4 text-slate2" /></li>
                    <li class="rounded-xl bg-gold/25 px-3 py-2 text-golddk">National</li>
                </ol>
            </div>
            <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                @foreach($divisions as $d)
                    <a href="{{ url('/') }}?q={{ urlencode($d['name']) }}#results" class="group rounded-2xl border border-hair p-3.5 transition hover:border-leaf hover:bg-mint/40">
                        <span class="flex items-center justify-between"><span class="text-sm font-bold">{{ $d['name'] }}</span><x-plat.icon name="arrow-up-right" class="w-3.5 h-3.5 text-slate2 opacity-0 transition group-hover:opacity-100" /></span>
                        <span class="block font-body text-xs text-slate2" lang="bn">{{ $d['bn'] }} · {{ $d['districts'] }} জেলা</span>
                        <span class="mt-1.5 block text-[13px] font-semibold text-leaf">{{ number_format($d['brands']) }} brands</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</section>
