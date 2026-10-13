{{--
    Compact brand card: logo, name, category and "View profile" only.
    The MetaSoft BD verified seal sits in the top-right corner only when
    `verified` is true (brands.is_verified). Paid placements keep a tiny
    "Ad" label — sponsored slots are always disclosed — and sample fill-ins
    keep their "Sample" label once the global preview notice is off.
    Sample (showcase) brands open the profile dialog (home.js,
    data-profile); real brands (`live`) link to their profile page.
    Lay cards out with .brand-grid (resources/css/app.css).
--}}
@props(['brand'])
@php $live = $brand['live'] ?? false; @endphp
<article class="relative flex h-full flex-col items-center rounded-xl border border-hair bg-white px-1.5 pb-1.5 pt-3 text-center transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_-12px_rgba(10,20,40,0.18)] sm:rounded-2xl sm:px-3 sm:pb-3 sm:pt-4">
    @if($brand['verified'] ?? false)
        <x-plat.verified size="w-4 h-4 sm:w-5 sm:h-5" class="absolute right-1 top-1 sm:right-2 sm:top-2" />
    @endif
    @if(($brand['tag'] ?? null) === 'sponsored')
        <span class="absolute left-1 top-1 rounded bg-slate-100 px-1 text-[9px] font-bold leading-4 text-slate2 sm:left-2 sm:top-2" title="Sponsored placement">Ad</span>
    @elseif(($brand['sample'] ?? false) && ! config('platform.showcase_preview'))
        <span class="absolute left-1 top-1 rounded bg-slate-100 px-1 text-[9px] font-semibold leading-4 text-slate2 sm:left-2 sm:top-2">Sample</span>
    @endif

    <x-plat.logo :initials="$brand['initials']" :from="$brand['from']" :to="$brand['to']" :size="56" :src="$brand['logo'] ?? null" :alt="$brand['name'].' logo'"
                 class="h-11! w-11! border border-hair sm:h-14! sm:w-14!" />
    <h3 class="mt-1.5 line-clamp-2 w-full break-words font-plat text-[11px] font-extrabold leading-tight text-night sm:mt-2.5 sm:text-[14px]" title="{{ $brand['name'] }}">{{ $brand['name'] }}</h3>
    <p class="mt-0.5 w-full truncate text-[10px] leading-tight text-slate2 sm:text-[12px]" title="{{ $brand['category'] }}">{{ $brand['category'] }}</p>

    <div class="mt-auto w-full pt-2 sm:pt-3">
        @if($live)
            <a href="{{ $brand['url'] }}"
               class="flex min-h-8 w-full items-center justify-center rounded-lg border border-hair px-1 text-[10px] font-bold leading-tight text-night transition after:absolute after:inset-0 hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:min-h-9 sm:text-[12.5px]">
                View Profile<span class="sr-only">: {{ $brand['name'] }}</span>
            </a>
        @else
            <button type="button" data-profile="brand:{{ $brand['slug'] }}" aria-haspopup="dialog"
                    class="flex min-h-8 w-full items-center justify-center rounded-lg border border-hair px-1 text-[10px] font-bold leading-tight text-night transition after:absolute after:inset-0 hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:min-h-9 sm:text-[12.5px]">
                View Profile<span class="sr-only">: {{ $brand['name'] }}</span>
            </button>
        @endif
    </div>
</article>
