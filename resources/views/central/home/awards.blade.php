<section id="awards" class="scroll-mt-20 bg-cream py-12 sm:py-16 lg:py-20">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="National awards" bn="অ্যাওয়ার্ড"
            title="Fair recognition, clear rules."
            sub="Free nominations, rule-based public voting and jury review."
            link="Award rules" href="#recognition" />

        {{-- "Voting open", the countdown and "Vote now" appear only when the server confirms an open campaign ($award['voting_open'], HomepageContent::openCampaigns). --}}
        @php $awardVoting = ($award['voting_open'] ?? false) && $votingOpen; @endphp
        <div class="mt-8 grid gap-5 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
            {{-- Featured award --}}
            <article class="relative min-w-0 overflow-hidden rounded-3xl bg-[linear-gradient(140deg,#052B4C,#0E528C)] p-5 text-white sm:p-9">
                <span aria-hidden="true" class="absolute -right-16 -top-16 h-64 w-64 rounded-full border-[28px] border-gold/10"></span>
                <div class="relative flex items-start justify-between gap-4">
                    <div class="flex flex-wrap gap-2">
                        <span @class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold', 'bg-flag' => $awardVoting, 'bg-white/15' => ! $awardVoting])><span @class(['h-1.5 w-1.5 rounded-full bg-white', 'animate-pulse' => $awardVoting])></span>{{ $award['status'] }}</span>
                        <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold">{{ $award['edition'] }}</span>
                    </div>
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-gold to-golddk shadow-lg shadow-black/30"><x-plat.icon name="trophy" class="w-7 h-7" /></span>
                </div>
                @php
                    // Phones: exactly two balanced lines, split at the word break nearest the middle and
                    // sized to the viewport (card content width is 100vw − 72px) so neither line wraps or clips.
                    $words = explode(' ', $award['title']);
                    $cut = 1;
                    foreach (range(1, max(1, count($words) - 1)) as $i) {
                        if (abs(mb_strlen(implode(' ', array_slice($words, 0, $i))) - mb_strlen($award['title']) / 2) < abs(mb_strlen(implode(' ', array_slice($words, 0, $cut))) - mb_strlen($award['title']) / 2)) {
                            $cut = $i;
                        }
                    }
                    $titleLines = array_filter([implode(' ', array_slice($words, 0, $cut)), implode(' ', array_slice($words, $cut))]);
                    $fit = round(max(array_map('mb_strlen', $titleLines)) * 0.62, 1);
                @endphp
                <h3 class="relative mt-4 max-w-lg text-[length:min(26px,calc((100vw_-_72px)/var(--fit)))] font-extrabold leading-[1.2] tracking-tight sm:mt-5 sm:text-[34px] sm:leading-[1.15]" style="--fit: {{ $fit }}">
                    @foreach($titleLines as $line)<span class="block whitespace-nowrap sm:inline sm:whitespace-normal">{{ $line }}</span>@if(! $loop->last) @endif @endforeach
                </h3>
                <p class="mt-2 font-body text-[17px] font-medium text-gold" lang="bn">{{ $award['bn'] }}</p>

                {{-- Real counts for a live award; the sample award shows only its configured category count. --}}
                @if($award['stats'])
                <dl class="mt-6 flex w-full overflow-hidden rounded-2xl border border-white/10 sm:w-fit">
                    @foreach($award['stats'] as $s)
                        <div class="min-w-0 flex-1 border-r border-white/10 px-3 py-3 last:border-r-0 sm:min-w-[120px] sm:px-4 sm:py-3.5">
                            <dd class="text-xl font-extrabold sm:text-2xl">{{ $s['value'] }}</dd>
                            <dt class="text-xs text-white/60">{{ $s['label'] }}</dt>
                        </div>
                    @endforeach
                </dl>
                @endif

                @if($award['closes_label'])
                <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-white/55">{{ $award['closes_title'] }}</p>
                        <p class="font-bold">{{ $award['closes_label'] }}</p>
                    </div>
                    @if($award['closes_at'])
                        <div class="flex gap-2" data-countdown="{{ $award['closes_at'] }}" aria-label="Time left">
                            @foreach(['days', 'hrs', 'min', 'sec'] as $unit)
                                <div class="w-12 rounded-xl bg-white/[0.08] py-2 text-center sm:w-14">
                                    <span class="block text-xl font-extrabold tabular-nums" data-unit="{{ $unit }}">--</span>
                                    <span class="text-[10px] text-white/55">{{ $unit }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                @endif

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
                    @if($awardVoting)
                        <a href="#voting" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-6 py-3 text-[15px] font-bold text-night hover:brightness-105">Vote now <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    @else
                        <a href="#join" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-6 py-3 text-[15px] font-bold text-night hover:brightness-105">List your brand free <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    @endif
                    <a href="{{ ($award['live'] ?? false) ? $award['url'] : '#recognition' }}" class="inline-flex items-center justify-center rounded-xl border border-white/30 px-6 py-3 text-[15px] font-bold hover:bg-white/10">View categories & rules</a>
                </div>
            </article>

            {{-- Other real public awards (none are invented); with none, the configured category list fills the column. --}}
            @if($otherAwards)
            <div class="flex flex-col gap-4">
                @foreach($otherAwards as $a)
                    @php $tone = ['voting' => 'bg-rose-50 text-rose-700', 'nominate' => 'bg-mint text-leafdk', 'jury' => 'bg-indigo-50 text-indigo-700', 'info' => 'bg-cloud text-slate2'][$a['state']]; @endphp
                    <article class="flex flex-1 items-center gap-4 rounded-2xl border border-hair bg-white p-5">
                        <x-plat.logo :initials="$a['initials']" :from="$a['from']" :to="$a['to']" :size="54" :src="$a['logo'] ?? null" :alt="$a['title'].' emblem'" />
                        <div class="min-w-0 flex-1">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $tone }}"><span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $a['status'] }}</span>
                            <h3 class="mt-1.5 text-base font-bold leading-snug">{{ $a['title'] }}</h3>
                            <p class="mt-0.5 text-[13px] text-slate2">{{ $a['category'] }}@if($a['when']) · {{ $a['when'] }}@endif</p>
                        </div>
                        <a href="{{ $a['url'] }}" class="hidden shrink-0 rounded-lg border border-hair px-3 py-2 text-[13px] font-bold hover:border-leaf hover:text-leaf sm:inline-flex">
                            {{ ['voting' => 'Vote', 'nominate' => 'Nominate', 'jury' => 'Follow', 'info' => 'View'][$a['state']] }}
                        </a>
                    </article>
                @endforeach
            </div>
            @else
                {{-- No other real awards yet: show the programme's configured categories (config('platform.award_categories')). --}}
                @php $cats = config('platform.award_categories'); @endphp
                <div class="flex min-w-0 flex-col rounded-3xl border border-hair bg-white p-5 sm:p-6">
                    <p class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-leaf">Award categories</p>
                    <h3 class="mt-1.5 text-lg font-extrabold tracking-tight">{{ count($cats) }} categories, two awards each</h3>
                    <p class="mt-1 text-[13.5px] text-slate2">People’s Choice and Jury Choice in every category.</p>
                    <ul class="mt-4 flex flex-wrap gap-2">
                        @foreach(array_slice($cats, 0, 12) as $c)
                            <li class="rounded-full bg-cloud px-3 py-1.5 text-[12.5px] font-semibold text-night">{{ $c }}</li>
                        @endforeach
                        @if(count($cats) > 12)<li class="rounded-full border border-dashed border-hair px-3 py-1.5 text-[12.5px] font-semibold text-slate2">+{{ count($cats) - 12 }} more</li>@endif
                    </ul>
                    <a href="#join" class="mt-auto inline-flex items-center gap-1.5 pt-5 text-sm font-bold text-leaf hover:underline">Nominate your brand free <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                </div>
            @endif
        </div>

        {{-- One national stage: every category is judged centrally, under one set of rules. --}}
        <div class="mt-5 flex items-center gap-4 rounded-2xl border border-hair bg-white p-4 sm:p-5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-gold to-golddk text-night"><x-plat.icon name="trophy" class="w-5 h-5" /></span>
            <div class="min-w-0">
                <h3 class="text-lg font-extrabold tracking-tight">One Nation. One Stage.</h3>
                <p class="text-sm text-slate2">Brands compete nationally under one set of rules.</p>
            </div>
        </div>
        <p class="mx-auto mt-6 flex w-fit max-w-full items-center justify-center gap-2 text-center text-[15px] font-semibold text-night"><x-plat.icon name="trophy" class="w-[18px] h-[18px] shrink-0 text-golddk" /> Award entry is free and doesn’t need membership.</p>
    </div>
</section>
