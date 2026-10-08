@props(['nominee', 'category'])
@php $b = $nominee['brand']; $lead = $nominee['rank'] === 1; @endphp
<article class="relative flex h-full flex-col rounded-[20px] border bg-white p-5 transition hover:shadow-[0_12px_32px_-14px_rgba(10,20,40,0.2)] {{ $lead ? 'border-leaf ring-1 ring-leaf' : 'border-hair' }}">
    <div class="flex items-start justify-between">
        <x-plat.logo :initials="$b['initials']" :from="$b['from']" :to="$b['to']" :size="52" />
        @if($lead)
            <span class="inline-flex items-center gap-1 rounded-full bg-gold/25 px-2.5 py-1 text-[11px] font-bold text-golddk"><x-plat.icon name="star" class="w-3 h-3" /> #1 Leading</span>
        @else
            <span class="rounded-full bg-cloud px-2.5 py-1 text-[11px] font-bold text-slate2">#{{ $nominee['rank'] }}</span>
        @endif
    </div>
    <h3 class="mt-4 flex items-center gap-1.5 font-plat text-[17px] font-extrabold text-night"><span class="truncate">{{ $b['name'] }}</span><x-plat.verified /></h3>
    <p class="text-[13px] text-slate2">{{ $b['category'] }} · {{ $b['district'] }}</p>
    <p class="mt-3 line-clamp-2 text-sm leading-relaxed text-slate2">{{ $b['description'] }}</p>
    <div class="mt-4">
        <div class="flex justify-between text-[13px] font-bold">
            <span class="text-night">{{ number_format($nominee['votes']) }} votes</span>
            <span class="text-leaf">{{ $nominee['pct'] }}%</span>
        </div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-cloud" role="progressbar" aria-valuenow="{{ $nominee['pct'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $b['name'] }} vote share">
            <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-leaf" style="width: {{ $nominee['bar'] }}%"></div>
        </div>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-2 pt-0">
        <button type="button" data-vote data-name="{{ $b['name'] }}" data-category="{{ $category }}" data-initials="{{ $b['initials'] }}" data-from="{{ $b['from'] }}" data-to="{{ $b['to'] }}" data-slug="{{ $b['slug'] }}"
                class="inline-flex items-center justify-center gap-1.5 rounded-[10px] bg-leaf px-3 py-2.5 text-[13px] font-bold text-white transition hover:bg-leafdk active:scale-[0.98] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">
            <x-plat.icon name="check" class="w-4 h-4" stroke="2.6" /> Vote
        </button>
        <button type="button" data-profile="brand:{{ $b['slug'] }}"
                class="rounded-[10px] border border-hair px-3 py-2.5 text-[13px] font-bold text-night transition hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
            Profile
        </button>
    </div>
</article>
