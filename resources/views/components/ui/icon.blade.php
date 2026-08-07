{{--
    Shared line-icon set for feature/benefit sections — replaces emoji as
    icons, which don't read as "premium SaaS" and render inconsistently
    across platforms/fonts. Stroke-based, single-color (currentColor), so
    it inherits whatever text color class is applied to it.

    This is separate from partials/icon.blade.php, which is specifically
    for social-platform brand marks (Facebook/Instagram/etc.) — this one
    is generic product/feature iconography.

    Props:
      name: 'storefront' | 'shield-check' | 'chart-trending' | 'megaphone'
          | 'whatsapp' | 'upload' | 'globe' | 'cart' | 'warehouse'
--}}
@props(['name'])

@php
    // WhatsApp uses its real (filled) brand mark instead of the stroke style,
    // for brand recognition — everything else shares one consistent line style.
    $isFilled = $name === 'whatsapp';

    // Explicit fallback instead of merge(['class' => 'w-6 h-6', ...]): Blade's
    // attribute merge APPENDS to an incoming class="" rather than replacing it,
    // so a caller-provided size (e.g. class="w-5 h-5") wouldn't reliably beat
    // the default in the CSS cascade — whichever utility Tailwind happens to
    // place later in the compiled stylesheet wins, regardless of which one
    // appears later in the HTML. Resolving the size here guarantees exactly
    // one size class ever reaches the element.
    $classes = $attributes->get('class') ?: 'w-6 h-6';
@endphp

<svg {{ $attributes->except('class')->merge([
        'class' => $classes,
        'viewBox' => '0 0 24 24',
        'fill' => $isFilled ? 'currentColor' : 'none',
        'stroke' => $isFilled ? 'none' : 'currentColor',
        'stroke-width' => '1.6',
        'stroke-linecap' => 'round',
        'stroke-linejoin' => 'round',
        'aria-hidden' => 'true',
    ]) }}>
    @switch($name)
        @case('storefront')
            <path d="M6 8h12l-1 12H7L6 8Z" />
            <path d="M9 8V6a3 3 0 0 1 6 0v2" />
            @break

        @case('shield-check')
            <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z" />
            <path d="M9 12l2 2 4-4" />
            @break

        @case('chart-trending')
            <path d="M4 19V5" />
            <path d="M4 19h16" />
            <path d="M7 15l4-4 3 3 5-6" />
            @break

        @case('megaphone')
            <path d="M3 10v4a1 1 0 0 0 1 1h2l7 4V5L6 9H4a1 1 0 0 0-1 1Z" />
            <path d="M17 9a4 4 0 0 1 0 6" />
            @break

        @case('whatsapp')
            <path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15s-.77.96-.94 1.16c-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37s-1.04 1.02-1.04 2.48 1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35M12.04 21.5h-.01c-1.75 0-3.47-.47-4.97-1.36l-.36-.21-3.7.97.99-3.61-.23-.37a9.86 9.86 0 01-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89 2.64 0 5.12 1.03 6.99 2.9a9.82 9.82 0 012.89 6.99c0 5.45-4.44 9.89-9.89 9.89M20.52 3.45A11.76 11.76 0 0012.04 0C5.5 0 .17 5.33.17 11.88c0 2.09.55 4.13 1.59 5.93L.07 24l6.33-1.66a11.85 11.85 0 005.64 1.44h.01c6.54 0 11.87-5.33 11.87-11.88 0-3.17-1.24-6.15-3.48-8.4" />
            @break

        @case('upload')
            <path d="M12 4v10" />
            <path d="M8 8l4-4 4 4" />
            <path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" />
            @break

        @case('globe')
            <circle cx="12" cy="12" r="8.5" />
            <path d="M3.5 12h17" />
            <path d="M12 3.5c2.5 2.3 4 5.3 4 8.5s-1.5 6.2-4 8.5c-2.5-2.3-4-5.3-4-8.5s1.5-6.2 4-8.5Z" />
            @break

        @case('cart')
            <circle cx="9" cy="20" r="1.3" />
            <circle cx="17" cy="20" r="1.3" />
            <path d="M3 4h2l2.2 11a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L20.5 8H6" />
            @break

        @case('warehouse')
            <path d="M3 10.5 12 4l9 6.5" />
            <path d="M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9" />
            <path d="M9 20v-6h6v6" />
            @break
    @endswitch
</svg>
