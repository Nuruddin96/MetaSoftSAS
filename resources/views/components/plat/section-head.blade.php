{{-- `sample`: the section shows demo content, so it carries a visible "Example content" label. --}}
@props(['eyebrow', 'title', 'sub' => null, 'link' => null, 'href' => '#', 'dark' => false, 'bn' => null, 'sample' => false])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="max-w-2xl">
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-bold uppercase tracking-[0.14em] {{ $dark ? 'text-gold' : 'text-leaf' }}">
            <span class="h-0.5 w-5 rounded {{ $dark ? 'bg-gold' : 'bg-leaf' }}"></span>{{ $eyebrow }}
            @if($bn)<span class="font-body text-[13px] normal-case tracking-normal font-semibold {{ $dark ? 'text-white/50' : 'text-slate2' }}">· {{ $bn }}</span>@endif
            @if($sample)<span class="rounded-full border border-dashed border-slate-300 bg-white px-2 py-0.5 text-[10.5px] font-bold normal-case tracking-normal text-slate2">Example content</span>@endif
        </p>
        <h2 class="mt-2 font-plat text-[22px] leading-[1.2] font-extrabold tracking-tight sm:text-[28px] lg:text-[32px] {{ $dark ? 'text-white' : 'text-night' }}">{{ $title }}</h2>
        @if($sub)<p class="mt-2 text-[14.5px] leading-relaxed sm:text-base {{ $dark ? 'text-white/70' : 'text-slate2' }}">{{ $sub }}</p>@endif
    </div>
    @if($link)
        <a href="{{ $href }}" class="group inline-flex shrink-0 items-center gap-1.5 text-sm font-bold {{ $dark ? 'text-gold' : 'text-leaf' }} hover:underline underline-offset-4 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
            {{ $link }} <x-plat.icon name="arrow-right" class="w-4 h-4 transition group-hover:translate-x-0.5" />
        </a>
    @endif
</div>
