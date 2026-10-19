{{--
    Concept C "Awards & recognition" on a navy stage. Real data only:
    past recipients appear once their portraits are supplied (HomeMedia);
    "Current nominees" uses HomepageContent::voteEntry() and only links into
    the existing /vote/{brand} and /voting/{campaign} pages, which re-check
    phase, category, vote limit and anti-abuse rules server-side — no vote
    button unless the server says voting is open. The featured award is
    $award (a real award when one exists, else the configured programme).
--}}
@php
    $e = $voteEntry;
    $open = $e && $e['phase'] === 'open';
    $awardUrl = ($award['live'] ?? false) ? $award['url'] : null;
    $categoryCount = count(config('platform.award_categories'));
@endphp
<section id="awards" class="relative isolate scroll-mt-20 overflow-hidden bg-night py-9 text-white sm:py-14" aria-labelledby="awardsTitle">
    <div aria-hidden="true" class="absolute inset-0 -z-10 bg-[linear-gradient(160deg,#052B4C,#0B2545_60%,#073B66)]"></div>
    <div class="{{ $wrap }}">
        <p class="flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-[0.16em] text-gold"><span class="h-px w-6 bg-gold"></span> Awards & recognition</p>
        <div class="fit-line"><h2 id="awardsTitle" class="fit-line-t mt-1.5 font-extrabold leading-tight tracking-tight sm:whitespace-nowrap" style="--k: 23.3; --min: 17px; --max: 23px">Celebrating entrepreneurs who build Bangladesh</h2></div>

        <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)] lg:gap-6">
            <div class="min-w-0">
                <div class="mb-2.5 flex items-center justify-between gap-3">
                    <p class="text-[12px] font-extrabold uppercase tracking-[0.12em] text-white/70">Past recipients</p>
                    @if($media['recipients'])@include('central.home.marquee-controls', ['id' => 'recipientsRow', 'dark' => true, 'compact' => true])@endif
                </div>
                @if($media['recipients'])
                    <div id="recipientsRow" data-marquee="12" class="-mx-4 flex gap-3 px-4 sm:mx-0 sm:px-0" aria-label="Past award recipients">
                        @foreach($media['recipients'] as $r)
                            <figure class="w-[40vw] max-w-[184px] shrink-0 overflow-hidden rounded-xl bg-white/[0.06] ring-1 ring-white/12 sm:w-[184px] sm:max-w-none">
                                <div class="relative">@include('central.home.photo', ['p' => ['src' => $r['src'], 'alt' => $r['caption'], 'portrait' => true], 'ratio' => '4/5', 'class' => 'rounded-none'])<span class="absolute bottom-1.5 left-1.5 rounded bg-gold px-1.5 text-[9.5px] font-extrabold uppercase leading-4 text-night">Past recipient</span></div>
                                <figcaption class="p-2.5 text-[13px] font-bold leading-snug">{{ $r['caption'] }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                @else
                    <div class="flex items-center gap-3 rounded-2xl border border-dashed border-white/20 bg-white/[0.04] p-4 sm:p-5">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10 text-gold"><x-plat.icon name="award" class="w-5 h-5" /></span>
                        <p class="text-[14px] leading-relaxed text-white/80">Award recipients will be featured here, each linked to their brand profile.</p>
                    </div>
                @endif

            </div>

            <div class="grid content-start gap-3">
                {{-- #voting kept for links shared before the redesign. --}}
                <div id="voting" class="scroll-mt-24 rounded-2xl border border-white/12 bg-white/[0.05] p-4 sm:p-5">
                    <p class="flex flex-wrap items-center gap-2 text-[12px] font-extrabold uppercase tracking-[0.12em] text-white/70">
                        Current nominees
                        @if($open)<span class="rounded bg-flag px-1.5 py-px text-[10px] font-extrabold tracking-wide text-white">LIVE</span>@endif
                        @if($e)<span class="rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-bold normal-case tracking-normal text-white/85">{{ $e['label'] }}</span>@endif
                    </p>
                    @if($open)
                        <p class="mt-1.5 text-[14px] leading-relaxed text-white/85">{{ $e['title'] }} — free to vote. {{ $e['rule'] }}.@if($e['ends']) Closes {{ $e['ends'] }}.@endif</p>
                        @if(($award['voting_open'] ?? false) && $award['closes_at'])
                            <div class="mt-3 flex gap-2" data-countdown="{{ $award['closes_at'] }}" aria-label="Time left to vote">
                                @foreach(['days', 'hrs', 'min', 'sec'] as $unit)
                                    <div class="w-12 rounded-lg bg-white/[0.08] py-1.5 text-center"><span class="block text-[17px] font-extrabold tabular-nums" data-unit="{{ $unit }}">--</span><span class="text-[10px] text-white/55">{{ $unit }}</span></div>
                                @endforeach
                            </div>
                        @endif
                        @if($e['nominees'])
                            <ul class="mt-3 grid gap-1.5" data-vote-finder>
                                @foreach(array_slice($e['nominees'], 0, 6) as $n)
                                    <li><a href="{{ $n['url'] }}" class="flex min-h-10 items-center justify-between gap-2 rounded-lg bg-white/[0.06] px-3 text-[14px] font-semibold hover:bg-white/10"><span class="truncate">{{ $n['name'] }}</span> <span class="shrink-0 text-[12.5px] font-bold text-gold">Vote →</span></a></li>
                                @endforeach
                            </ul>
                        @endif
                        <a href="{{ $e['url'] }}" class="mt-3 inline-flex min-h-10 items-center gap-1.5 text-[14px] font-bold text-gold hover:underline">Vote now · see all nominees <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    @elseif($e && $e['phase'] === 'scheduled')
                        <p class="mt-1.5 text-[14px] leading-relaxed text-white/85">Public voting for {{ $e['title'] }} @if($e['starts'])opens {{ $e['starts'] }}@else opens soon @endif. Voting is free.</p>
                    @elseif($e && $e['phase'] === 'paused')
                        <p class="mt-1.5 text-[14px] leading-relaxed text-white/85">Public voting for {{ $e['title'] }} is paused for now. Please check back soon.</p>
                    @elseif($e)
                        <p class="mt-1.5 text-[14px] leading-relaxed text-white/85">Public voting for {{ $e['title'] }} has ended. Nominee profiles appear during active voting campaigns.</p>
                    @else
                        <p class="mt-1.5 text-[14px] leading-relaxed text-white/85">Nominee profiles appear during active voting campaigns.</p>
                    @endif
                </div>

                <div class="rounded-2xl bg-[linear-gradient(135deg,#1769AA,#073B66)] p-4 ring-1 ring-white/15 sm:p-5">
                    <p class="text-[12px] font-extrabold uppercase tracking-[0.12em] text-gold">{{ $award['status'] }} · free entry</p>
                    <p class="mt-2 text-[18px] font-extrabold leading-snug">{{ $award['title'] }}</p>
                    @if($award['bn'])<p class="font-body text-[14px] text-sky-200" lang="bn">{{ $award['bn'] }}</p>@endif
                    <p class="mt-2 text-[13.5px] text-white/80">{{ $categoryCount }} categories · People’s Choice and Jury Choice in every category.</p>
                    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                        <a href="{{ $joinUrl }}" class="inline-flex h-11 items-center justify-center rounded-xl bg-gold px-5 text-[14px] font-extrabold text-night hover:brightness-105">Create profile to enter</a>
                        @if($awardUrl)<a href="{{ $awardUrl }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-white/30 px-5 text-[14px] font-bold hover:bg-white/10">Categories & rules</a>@endif
                    </div>
                    {{-- How each award is decided (Showcase::recognitionTypes; jury size, weights and age limits appear only when configured). --}}
                    <details class="group mt-4 border-t border-white/15 pt-3">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 text-[13.5px] font-bold">How winners are chosen <x-plat.icon name="chevron-down" class="w-4 h-4 transition group-open:rotate-180" /></summary>
                        <ul class="mt-2 grid gap-1.5 text-[13px] text-white/80">
                            @foreach($recognition as $r)
                                <li><b class="text-white">{{ $r['name'] }}</b> — {{ $r['desc'] }} <span class="text-white/60">({{ $r['how'] }})</span></li>
                            @endforeach
                        </ul>
                    </details>
                </div>
                {{-- Voting rules, stated only as strongly as VoteService enforces them. --}}
                <p class="flex items-start gap-2 px-1 text-[12.5px] leading-relaxed text-white/70"><x-plat.icon name="lock" class="mt-0.5 w-3.5 h-3.5 shrink-0" /> <span>One vote per mobile number per category in each voting period. Numbers are not OTP-verified; votes are screened for abuse. Paid promotions never affect results.</span></p>
            </div>
        </div>
        <p class="mx-auto mt-7 flex w-fit max-w-full items-center justify-center gap-2 text-center text-[15px] font-semibold text-white"><x-plat.icon name="trophy" class="w-[18px] h-[18px] shrink-0 text-gold" /> Award entry is free and doesn’t need membership.</p>
    </div>
</section>
