{{--
    Voting nominee card. Real nominees (`live`, from a real campaign) show
    their uploaded logo and real verified status and link to their
    permanent /vote/{slug} page; sample nominees keep the homepage's
    preview vote dialog (home.js, data-vote); every Profile link opens the
    brand's own /brand/{slug} page. Vote counts only when the campaign
    shows real counts (sample tabs never do). The Vote button appears only
    when `open` — the server confirmed the campaign is open; sample
    nominees never get one and carry a "Sample" label.
--}}
@props(['nominee', 'category', 'showCounts' => true, 'open' => false])
@php $b = $nominee['brand']; $lead = $nominee['rank'] === 1; $live = $b['live'] ?? false; @endphp
<article class="relative flex h-full flex-col rounded-[18px] border bg-white p-4 transition hover:shadow-[0_12px_32px_-14px_rgba(10,20,40,0.2)] {{ $lead && $showCounts ? 'border-leaf ring-1 ring-leaf' : 'border-hair' }}">
    <div class="flex items-start justify-between">
        <x-plat.logo :initials="$b['initials']" :from="$b['from']" :to="$b['to']" :size="44" :src="$b['logo'] ?? null" :alt="$b['name'].' logo'" />
        @if(! $live)
            <span class="rounded-full bg-cloud px-2.5 py-1 text-[11px] font-bold text-slate2">Sample</span>
        @elseif(! $showCounts)
        @elseif($lead)
            <span class="inline-flex items-center gap-1 rounded-full bg-gold/25 px-2.5 py-1 text-[11px] font-bold text-golddk"><x-plat.icon name="star" class="w-3 h-3" /> #1 Leading</span>
        @else
            <span class="rounded-full bg-cloud px-2.5 py-1 text-[11px] font-bold text-slate2">#{{ $nominee['rank'] }}</span>
        @endif
    </div>
    <h3 class="mt-3 flex items-center gap-1.5 font-plat text-base font-extrabold text-night"><span class="truncate">{{ $b['name'] }}</span>@if($b['verified'] ?? false)<x-plat.verified />@endif</h3>
    <p class="text-[13px] text-slate2">{{ $b['category'] }} · {{ $b['district'] }}</p>
    <p class="mt-2 line-clamp-2 text-[13px] leading-snug text-slate2">{{ $b['description'] }}</p>
    @if($showCounts)
        <div class="mt-3">
            <div class="flex justify-between text-[13px] font-bold">
                <span class="text-night">{{ number_format($nominee['votes']) }} votes</span>
                <span class="text-leaf">{{ $nominee['pct'] }}%</span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-cloud" role="progressbar" aria-valuenow="{{ $nominee['pct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $b['name'] }} vote share">
                <div class="h-full rounded-full bg-gradient-to-r from-sky-400 to-leaf" style="width: {{ $nominee['bar'] }}%"></div>
            </div>
        </div>
    @endif
    <div class="mt-auto grid grid-cols-2 gap-2 pt-3">
        @if($live && $open)
            <a href="{{ $b['vote_url'] }}" class="inline-flex items-center justify-center gap-1.5 rounded-[10px] bg-leaf px-3 py-2 text-[13px] font-bold text-white transition hover:bg-leafdk focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                <x-plat.icon name="check" class="w-4 h-4" stroke="2.6" /> Vote<span class="sr-only"> for {{ $b['name'] }}</span>
            </a>
            <a href="{{ $b['url'] }}" class="inline-flex items-center justify-center rounded-[10px] border border-hair px-3 py-2 text-[13px] font-bold text-night transition hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">Profile</a>
        @elseif($open)
            <button type="button" data-vote data-name="{{ $b['name'] }}" data-category="{{ $category }}" data-initials="{{ $b['initials'] }}" data-from="{{ $b['from'] }}" data-to="{{ $b['to'] }}" data-slug="{{ $b['slug'] }}" data-logo="{{ $b['logo'] ?? '' }}"
                    class="inline-flex items-center justify-center gap-1.5 rounded-[10px] bg-leaf px-3 py-2 text-[13px] font-bold text-white transition hover:bg-leafdk active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
                <x-plat.icon name="check" class="w-4 h-4" stroke="2.6" /> Vote
            </button>
            <a href="{{ $b['url'] }}"
               class="inline-flex items-center justify-center rounded-[10px] border border-hair px-3 py-2 text-[13px] font-bold text-night transition hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">Profile</a>
        @else
            <a href="{{ $b['url'] }}" class="col-span-2 inline-flex items-center justify-center rounded-[10px] border border-hair px-3 py-2 text-[13px] font-bold text-night transition hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">View profile<span class="sr-only">: {{ $b['name'] }}</span></a>
        @endif
    </div>
</article>
