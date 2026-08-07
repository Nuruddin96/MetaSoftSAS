{{--
    Top-level <section> wrapper — background tone + vertical rhythm in one
    place, so every marketing/landing section shares the same spacing scale
    instead of each hand-rolling its own py-* value.

    Props:
      tone:    'light' | 'white' | 'dark' | 'transparent'  (default: 'light')
      spacing: 'default' | 'compact' | 'none'               (default: 'default')

    spacing="none" is for sections that need full-bleed decorative children
    (absolutely-positioned backgrounds, edge-to-edge stripes) and handle
    their own inner padding instead.
--}}
@props(['tone' => 'light', 'spacing' => 'default'])

@php
    $tones = [
        'light'       => 'bg-paper text-ink',
        'white'       => 'bg-white text-ink',
        'dark'        => 'bg-ink text-white',
        'transparent' => '',
    ];

    $spacings = [
        'default' => 'py-16 md:py-24',
        'compact' => 'py-10 md:py-14',
        'none'    => '',
    ];
@endphp

<section {{ $attributes->merge(['class' => trim(($tones[$tone] ?? $tones['light']) . ' ' . ($spacings[$spacing] ?? $spacings['default']))]) }}>
    {{ $slot }}
</section>
