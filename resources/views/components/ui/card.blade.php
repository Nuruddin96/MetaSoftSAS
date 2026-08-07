{{--
    Shared card surface: rounded-card radius, hairline border. Every
    feature/pricing/testimonial/dashboard-widget card should use this
    instead of repeating "bg-white rounded-2xl border border-ink/5" per
    section.

    Props:
      hoverable: bool                                  (default: false)
      padding:   'none' | 'sm' | 'default' | 'lg'       (default: 'default')
      tone:      'white' | 'amber'                      (default: 'white')

    padding="none" is for cards that need edge-to-edge internal sections
    (e.g. a header strip with its own border-bottom, a table, a list with
    per-row dividers) rather than one uniform padding around everything.

    tone="amber" is for inline warning/notice cards (e.g. a trial-ending
    banner) — bg-amber/15 + border-amber/40 instead of the default white.
--}}
@props(['hoverable' => false, 'padding' => 'default', 'tone' => 'white'])

@php
    $tones = [
        'white' => 'bg-white border-ink/5',
        'amber' => 'bg-amber/15 border-amber/40',
    ];

    $paddings = [
        'none'    => '',
        'sm'      => 'p-4',
        'default' => 'p-6',
        'lg'      => 'p-8',
    ];

    $hover = $hoverable
        ? 'hover:border-leaf/30 hover:shadow-lg hover:-translate-y-0.5 transition'
        : '';

    $classes = trim(
        'rounded-card border '
        . ($tones[$tone] ?? $tones['white']) . ' '
        . ($paddings[$padding] ?? $paddings['default']) . ' '
        . $hover
    );
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</div>
