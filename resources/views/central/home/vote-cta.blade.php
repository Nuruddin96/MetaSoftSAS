{{--
    "Vote for Your Favorite Brand" entry point, right after Our Brands.
    HomepageContent::voteEntry() picks the campaign; this block only links
    into the existing /vote/{brand} and /voting/{campaign} pages, which
    re-check the campaign phase, category, vote limit and anti-abuse rules
    server-side. When voting isn't open it shows that state, never a vote
    button. With no real campaign yet (sample mode) it points to the award
    and the preview nominees below.
--}}
@php
    $e = $voteEntry;
    $open = $e && $e['phase'] === 'open';
    $finder = $open && $e['nominees'];
@endphp
<section id="vote-now" class="scroll-mt-20 bg-white py-8 sm:py-12" aria-labelledby="voteNowTitle">
    <div class="{{ $wrap }}">
        <div class="overflow-hidden rounded-3xl bg-[linear-gradient(135deg,#0A1428_0%,#0E2350_60%,#00513C_100%)] p-5 text-white sm:p-8 lg:flex lg:items-center lg:justify-between lg:gap-10 lg:p-10">
            <div class="max-w-xl">
                <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[12px] font-bold">
                    @if($open)
                        <span class="h-2 w-2 animate-pulse rounded-full bg-flag"></span> {{ $e['label'] }}
                    @elseif($e)
                        <span class="h-2 w-2 rounded-full bg-white/50"></span> {{ $e['label'] }}
                    @else
                        <span class="h-2 w-2 rounded-full bg-gold"></span> Voting opens soon
                    @endif
                </p>
                <h2 id="voteNowTitle" class="mt-3 font-plat text-2xl font-extrabold leading-tight tracking-tight sm:text-3xl">Vote for Your Favorite Brand</h2>
                <p class="mt-2 text-[14.5px] leading-relaxed text-white/75">
                    @if($open)
                        {{ $e['title'] }} — free to vote. {{ $e['rule'] }}.@if($e['ends']) Closes {{ $e['ends'] }}.@endif
                    @elseif($e && $e['phase'] === 'scheduled')
                        Public voting for {{ $e['title'] }} @if($e['starts'])opens {{ $e['starts'] }}@else opens soon @endif. Voting is free.
                    @elseif($e && $e['phase'] === 'paused')
                        Public voting for {{ $e['title'] }} is paused for now. Please check back soon.
                    @elseif($e)
                        Public voting for {{ $e['title'] }} has ended. Thank you to everyone who voted.
                    @else
                        Public voting for the {{ config('platform.award_name') }} hasn’t opened yet. Voting will be free — explore the award categories in the meantime.
                    @endif
                </p>
            </div>

            <div class="mt-5 w-full lg:mt-0 lg:max-w-md">
                @if($finder)
                    {{-- Pick a nominee → straight to its /vote/{slug} page (home.js); without JS, or with no exact match, the campaign page lists every nominee. --}}
                    <form action="{{ $e['url'] }}" method="get" data-vote-finder class="flex items-center gap-2 rounded-2xl bg-white p-1 shadow-xl shadow-black/20">
                        <label for="voteFinder" class="sr-only">Find a nominated brand</label>
                        <x-plat.icon name="search" class="ml-2 w-5 h-5 shrink-0 text-slate2" />
                        <input id="voteFinder" type="search" list="voteFinderList" autocomplete="off" placeholder="Find a nominated brand…"
                               class="h-9 min-w-0 flex-1 bg-transparent text-base text-night outline-none placeholder:text-slate2 sm:text-[15px]">
                        <datalist id="voteFinderList">
                            @foreach($e['nominees'] as $n)
                                <option value="{{ $n['name'] }}" data-url="{{ $n['url'] }}"></option>
                            @endforeach
                        </datalist>
                        <button type="submit" class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl bg-flag px-4 text-sm font-bold text-white transition hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-flag focus-visible:ring-offset-2">
                            <x-plat.icon name="vote" class="w-4 h-4" /> Vote
                        </button>
                    </form>
                    <a href="{{ $e['url'] }}" class="mt-3 inline-flex min-h-10 items-center gap-1.5 text-sm font-bold text-gold hover:underline">See all {{ count($e['nominees']) }} nominees <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                @elseif($e)
                    <a href="{{ $e['url'] }}" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-gold px-6 text-[15px] font-bold text-night transition hover:brightness-105 sm:w-auto">
                        {{ $open ? 'See the nominees' : 'View categories' }} <x-plat.icon name="arrow-right" class="w-4 h-4" />
                    </a>
                @else
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <a href="#awards" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-gold px-6 text-[15px] font-bold text-night transition hover:brightness-105">About the awards <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                        <a href="#voting" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/30 px-6 text-[15px] font-bold text-white transition hover:bg-white/10">Preview nominees</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
