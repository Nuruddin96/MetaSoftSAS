{{--
    MetaSoft BD verification badge: the same blue scalloped seal as before,
    with the MetaSoft BD "M" logo mark (solid flat top edge, V cut from below, slanted legs —
    public/images/icons/icon-192.png) in white at its centre instead of a tick.
    Shown only when Super Admin has verified the brand (brands.is_verified)
    — independent of featured, sponsored or any award status.
--}}
@props(['size' => 'w-4 h-4'])
<svg {{ $attributes->merge(['class' => "$size shrink-0 text-sky-500"]) }} viewBox="0 0 24 24" role="img" aria-label="MetaSoft BD verified">
    <title>MetaSoft BD verified</title>
    <path fill="currentColor" d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/>
    <path fill="#fff" d="M6.2 7.6h11.6l-.55 9.3-2.3-5.4L12 14.8l-2.95-3.3-2.3 5.4Z"/>
</svg>
