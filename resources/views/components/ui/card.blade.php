{{--
    Shared card surface: rounded-card radius, hairline border. Every
    feature/pricing/testimonial/dashboard-widget card should use this
    instead of repeating "bg-white rounded-2xl border border-ink/5" per
    section.

    Props:
      hoverable: bool                                  (default: false)
      padding:   'none' | 'sm' | 'default' | 'lg'       (default: 'default')
      tone:      'white' | 'amber'                      (default: 'white')
      radius:    'card' | 'none'                        (default: 'card')

    padding="none" is for cards that need edge-to-edge internal sections
    (e.g. a header strip with its own border-bottom, a table, a list with
    per-row dividers) rather than one uniform padding around everything.

    tone="amber" is for inline warning/notice cards (e.g. a trial-ending
    banner) — bg-amber/15 + border-amber/40 instead of the default white.

    radius="none" is for callers that need a different (typically
    responsive, e.g. a tighter radius on mobile) corner radius than the
    shared rounded-card token — same "opt out, supply your own via the
    class attribute" pattern as padding="none", so a caller-supplied
    rounded-* class never has to fight this component's own for
    precedence.
--}}
@props(['hoverable' => false, 'padding' => 'default', 'tone' => 'white', 'radius' => 'card'])

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

    $radii = [
        'card' => 'rounded-card',
        'none' => '',
    ];

    $hover = $hoverable
        ? 'hover:border-leaf/30 hover:shadow-lg hover:-translate-y-0.5 transition'
        : '';

    $classes = trim(
        ($radii[$radius] ?? $radii['card']) . ' border '
        . ($tones[$tone] ?? $tones['white']) . ' '
        . ($paddings[$padding] ?? $paddings['default']) . ' '
        . $hover
    );
@endphp

<div {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</div>
