{{--
    Every button/CTA across the marketing site (and, going forward, the
    rest of the SaaS) should render through this component instead of a
    hand-rolled <a>/<button> with its own one-off classes.

    Renders as <a> when href is given, otherwise <button type="{{ $type }}">
    — so the same component covers links (register CTA) and form submits
    (panel actions) without duplicating the variant styles twice.

    Props:
      href:    string|null                                    (default: null)
      variant: 'primary' | 'accent' | 'outline' | 'ghost'      (default: 'primary')
      size:    'default' | 'lg'                                (default: 'default')
      type:    'button' | 'submit' | 'reset' — only used when href is null
--}}
@props(['href' => null, 'variant' => 'primary', 'size' => 'default', 'type' => 'button'])

@php
    $base = 'inline-flex items-center justify-center gap-2 font-semibold rounded-btn transition '
          . 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2';

    $variants = [
        'primary' => 'bg-ink text-white hover:bg-ink/90',
        'accent'  => 'bg-leaf text-white hover:bg-leafdk',
        'outline' => 'border border-ink/15 text-ink hover:bg-white',
        'ghost'   => 'text-ink hover:bg-ink/5',
    ];

    $sizes = [
        'default' => 'px-6 py-3 text-sm',
        'lg'      => 'px-7 py-3.5 text-base',
    ];

    $classes = trim($base . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['default']));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
