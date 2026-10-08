@props(['eyebrow', 'title', 'sub' => null, 'link' => null, 'href' => '#', 'dark' => false, 'bn' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="max-w-2xl">
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-bold uppercase tracking-[0.14em] {{ $dark ? 'text-gold' : 'text-leaf' }}">
            <span class="h-0.5 w-5 rounded {{ $dark ? 'bg-gold' : 'bg-leaf' }}"></span>{{ $eyebrow }}
            @if($bn)<span class="font-body text-[13px] normal-case tracking-normal font-semibold {{ $dark ? 'text-white/50' : 'text-slate2' }}">· {{ $bn }}</span>@endif
        </p>
        <h2 class="mt-3 font-plat text-[28px] leading-[1.15] font-extrabold tracking-tight sm:text-4xl {{ $dark ? 'text-white' : 'text-night' }}">{{ $title }}</h2>
        @if($sub)<p class="mt-3 text-base leading-relaxed sm:text-[17px] {{ $dark ? 'text-white/70' : 'text-slate2' }}">{{ $sub }}</p>@endif
    </div>
    @if($link)
        <a href="{{ $href }}" class="group inline-flex shrink-0 items-center gap-1.5 text-sm font-bold {{ $dark ? 'text-gold' : 'text-leaf' }} hover:underline underline-offset-4 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
            {{ $link }} <x-plat.icon name="arrow-right" class="w-4 h-4 transition group-hover:translate-x-0.5" />
        </a>
    @endif
</div>
