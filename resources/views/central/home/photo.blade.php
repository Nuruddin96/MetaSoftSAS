{{--
    One photo slot: the real photo ($p from App\Support\Home\HomeMedia), or —
    while that material hasn't been supplied — a quiet branded tile with a
    short label. Never a stock photo standing in for a real event.
    $ratio (CSS aspect-ratio), $class (radius etc.), $label, $dark, $position (object-position).
--}}
@php $dark = $dark ?? false; @endphp
<div @class(['relative overflow-hidden bg-[#E2ECF7]', $class ?? 'rounded-xl']) style="aspect-ratio: {{ $ratio ?? '4/3' }}">
    @if($p['src'] ?? null)
        <img src="{{ $p['src'] }}" alt="{{ $p['alt'] ?? '' }}" loading="lazy" decoding="async" draggable="false"
             class="absolute inset-0 h-full w-full object-cover" style="object-position: {{ $position ?? (($p['portrait'] ?? false) ? 'center 22%' : 'center') }}">
    @else
        <div @class(['absolute inset-0 flex flex-col items-center justify-center gap-1.5 p-3 text-center',
                     'bg-[linear-gradient(140deg,rgba(255,255,255,0.08),rgba(255,255,255,0.02))] text-white/70 ring-1 ring-inset ring-white/12' => $dark,
                     'bg-[linear-gradient(140deg,#EEF4FB,#E2ECF7)] text-slate2 ring-1 ring-inset ring-hair' => ! $dark])>
            <x-plat.icon :name="$icon ?? 'star'" class="w-5 h-5 opacity-70" />
            <span class="text-[12px] font-semibold leading-tight">{{ $label ?? '' }}</span>
        </div>
    @endif
</div>
