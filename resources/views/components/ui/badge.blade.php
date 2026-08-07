{{--
    Small pill label — eyebrow text above headlines, "popular" pricing
    tags, section tags. Replaces the several slightly-different hand-rolled
    pill styles that existed across the old landing page.

    Props:
      tone: 'leaf' | 'amber' | 'white'  (default: 'leaf')
--}}
@props(['tone' => 'leaf'])

@php
    $tones = [
        'leaf'  => 'bg-leaf/10 text-leafdk',
        'amber' => 'bg-amber/15 text-ink',
        'white' => 'bg-white text-ink shadow-sm',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-xs font-semibold tracking-wide px-3 py-1.5 rounded-pill ' . ($tones[$tone] ?? $tones['leaf'])]) }}>
    {{ $slot }}
</span>
