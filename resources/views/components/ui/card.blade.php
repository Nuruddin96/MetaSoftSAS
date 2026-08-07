{{--
    Shared card surface: white background, rounded-card radius, hairline
    border. Every feature/pricing/testimonial card should use this instead
    of repeating "bg-white rounded-2xl border border-ink/5" per section.

    Props:
      hoverable: bool                          (default: false)
      padding:   'sm' | 'default' | 'lg'        (default: 'default')
--}}
@props(['hoverable' => false, 'padding' => 'default'])

@php
    $paddings = [
        'sm'      => 'p-4',
        'default' => 'p-6',
        'lg'      => 'p-8',
    ];

    $hover = $hoverable
        ? 'hover:border-leaf/30 hover:shadow-lg hover:-translate-y-0.5 transition'
        : '';
@endphp

<div {{ $attributes->merge(['class' => trim('bg-white rounded-card border border-ink/5 ' . ($paddings[$padding] ?? $paddings['default']) . ' ' . $hover)]) }}>
    {{ $slot }}
</div>
