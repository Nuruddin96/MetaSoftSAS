{{-- Controls for a [data-marquee] row ($id): back / pause-play / forward (home.js). Pause hides under prefers-reduced-motion, when nothing moves. $dark = on navy, $compact = smaller. --}}
@php
    $dark = $dark ?? false;
    $btn = 'inline-flex items-center justify-center rounded-full text-[12.5px] font-bold focus-visible:outline-none focus-visible:ring-2 '
        .(($compact ?? false) ? 'h-8 min-w-8 ' : 'h-9 min-w-9 ')
        .($dark ? 'border border-white/20 text-white hover:bg-white/10 focus-visible:ring-white' : 'border border-hair bg-white text-night hover:border-leaf focus-visible:ring-leaf');
@endphp
<div class="flex shrink-0 items-center gap-1.5" data-marquee-controls="{{ $id }}">
    <button type="button" data-mq="prev" aria-controls="{{ $id }}" aria-label="Scroll back" class="{{ $btn }}"><x-plat.icon name="chevron-right" class="w-4 h-4 rotate-180" /></button>
    <button type="button" data-mq="pause" aria-controls="{{ $id }}" aria-pressed="false" class="{{ $btn }} gap-1 px-3"><span data-label>Pause</span><span class="sr-only"> automatic scrolling</span></button>
    <button type="button" data-mq="next" aria-controls="{{ $id }}" aria-label="Scroll forward" class="{{ $btn }}"><x-plat.icon name="chevron-right" class="w-4 h-4" /></button>
</div>
