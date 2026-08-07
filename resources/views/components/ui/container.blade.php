{{--
    Consistent max-width + horizontal padding wrapper, used inside every
    <x-ui.section>. Three widths cover every layout this SaaS needs:
    narrow (forms, focused copy), default (most sections), wide (dense grids).

    Props:
      size: 'narrow' | 'default' | 'wide'  (default: 'default')
--}}
@props(['size' => 'default'])

@php
    $sizes = [
        'narrow'  => 'max-w-4xl',
        'default' => 'max-w-6xl',
        'wide'    => 'max-w-7xl',
    ];
@endphp

<div {{ $attributes->merge(['class' => ($sizes[$size] ?? $sizes['default']) . ' mx-auto px-4 sm:px-6']) }}>
    {{ $slot }}
</div>
