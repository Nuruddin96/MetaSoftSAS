{{--
    MetaSoft BD verification badge — the official supplied artwork (blue
    scalloped seal, white MetaSoft "M"): public/images/badges/metasoft-verified.png,
    only cropped and given a transparent backdrop. Shown only when Super
    Admin has verified the brand (brands.is_verified) — independent of
    featured, sponsored or any award status.
--}}
@props(['size' => 'w-4 h-4'])
<img src="{{ asset('images/badges/metasoft-verified.png') }}" alt="MetaSoft BD verified" title="MetaSoft BD verified" width="16" height="16" loading="lazy"
     {{ $attributes->merge(['class' => "$size inline-block shrink-0 select-none"]) }}>
