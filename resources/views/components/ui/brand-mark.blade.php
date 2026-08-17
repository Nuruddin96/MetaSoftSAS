{{--
    The actual MetaSoft BD logo mark + wordmark, replacing the generic
    green-square-with-a-letter placeholder that used to sit inline at every
    central-site header/auth-page (login, register, forgot/reset password,
    find-shop, services, landing nav + footer) — 8 near-identical copies of
    the same placeholder markup, now this one component. Reuses
    images/icons/icon-192.png — the same already-centered, already-
    generated-from-the-source-SVG PNG the PWA splash screen
    (x-ui.splash) already uses, for the same "pre-rasterized is lighter to
    inline than the raw SVG's filters/masks" reason documented there.

    Props:
      size: 'sm' | 'default'   (default: 'default') — matches the two
            sizes these call sites already used (landing/services w-8 h-8,
            auth pages w-9 h-9) rather than introducing a third.
--}}
@props(['size' => 'default'])

@php
    $sizeClasses = ['sm' => 'w-8 h-8', 'default' => 'w-9 h-9'];
    $sizePx = ['sm' => 32, 'default' => 36];
    $class = $sizeClasses[$size] ?? $sizeClasses['default'];
    $px = $sizePx[$size] ?? $sizePx['default'];
@endphp

<img src="{{ asset('images/icons/icon-192.png') }}" alt="MetaSoft BD" width="{{ $px }}" height="{{ $px }}"
     class="{{ $class }} rounded shrink-0 object-contain">
