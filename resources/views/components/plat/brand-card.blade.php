{{--
    Square brand card: logo, name, category and "View Profile" only.
    The MetaSoft BD verified seal sits in the top-right corner only when
    `verified` is true (brands.is_verified — set by Super Admin, approved
    brands only). Paid placements keep a small "Sponsored" label — sponsored
    slots are always disclosed — and sample fill-ins (never a registered `live` brand) always keep
    a small red dot with "Sample brand" title/screen-reader text. "View Profile" always opens the
    brand's own /brand/{slug} page (sample brands included).
    Lay cards out with .brand-grid (resources/css/app.css); sizes are tuned
    so the card stays square from 320px phones to desktop.
--}}
@props(['brand'])
<article class="relative flex aspect-square min-w-0 flex-col items-center justify-center rounded-xl border border-leaf/15 bg-white p-1 text-center min-[375px]:p-1.5 transition duration-200 hover:-translate-y-0.5 hover:border-leaf/40 hover:shadow-[0_12px_32px_-12px_rgba(10,20,40,0.18)] sm:rounded-2xl sm:px-2.5 sm:py-2.5">
    @if($brand['verified'] ?? false)
        <x-plat.verified size="w-4 h-4 sm:w-[18px] sm:h-[18px]" class="absolute right-1 top-1 sm:right-2 sm:top-2" />
    @endif
    @if(($brand['tag'] ?? null) === 'sponsored')
        <span class="absolute left-1 top-1 rounded bg-slate-100 px-1 text-[9px] font-bold leading-4 text-slate2 sm:left-2 sm:top-2" title="Paid placement">Sponsored</span>
    @elseif(($brand['sample'] ?? false) && ! ($brand['live'] ?? false))
        <span class="absolute left-1.5 top-1.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white sm:left-2.5 sm:top-2.5 sm:h-2.5 sm:w-2.5" title="Sample brand — not a registered business"><span class="sr-only">Sample brand</span></span>
    @endif

    <x-plat.logo :initials="$brand['initials']" :from="$brand['from']" :to="$brand['to']" :size="56" :src="$brand['logo'] ?? null" :alt="$brand['name'].' logo'"
                 class="h-6! w-6! border border-hair min-[375px]:h-9! min-[375px]:w-9! sm:h-11! sm:w-11! xl:h-14! xl:w-14!" />
    <h3 class="mt-1 w-full truncate px-0.5 font-plat text-[10.5px] font-extrabold leading-tight text-night min-[375px]:text-[11px] sm:mt-1.5 sm:text-[13px] xl:text-[14px]" title="{{ $brand['name'] }}">{{ $brand['name'] }}</h3>
    <p class="w-full truncate px-0.5 text-[10px] leading-tight text-slate2 sm:text-[11px] xl:text-[12px]" title="{{ $brand['category'] }}">{{ $brand['category'] }}</p>

    <a href="{{ $brand['url'] }}"
       class="mt-1 flex h-[18px] w-full max-w-[120px] items-center justify-center rounded-md border border-leaf/20 px-1 text-[10px] font-bold leading-none text-leafdk transition after:absolute after:inset-0 hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:mt-2 sm:h-7 sm:rounded-lg sm:text-[12px]">
        View Profile<span class="sr-only">: {{ $brand['name'] }}</span>
    </a>
</article>
