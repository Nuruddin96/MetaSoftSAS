{{--
    Branded full-page loading state — shown on initial panel load (see
    layouts/panel.blade.php, #appSplash) and reusable anywhere else a
    genuine full-page loading moment is needed later. Facebook-blue
    (#1877F2) background with only the white "M" mark centered — no
    wordmark, no other decoration, per the dedicated splash-screen spec.

    Deliberately NOT the app/launcher icon (icon-192.png / icon-512.png,
    still blue-on-transparent, untouched) — this uses
    public/images/icons/splash-logo.png (same mark, prepared specifically
    for this splash use) recolored to solid white via
    filter: brightness(0) invert(1). That filter only works because the
    source PNG has a genuine alpha-transparent background (confirmed via
    its PNG color type) — it zeroes every opaque pixel's RGB to black
    while leaving alpha untouched, then inverts black to white, so the
    exact existing mark silhouette renders in pure white regardless of
    its original blue gradient. The source file itself is never modified.

    Not for per-button/per-form loading — that's the existing .btn-loading
    + .btn-spinner mechanism in resources/js/app.js, deliberately left
    alone (a spinner, not this logo, is correct for a small tap target).
--}}
@props(['id' => 'appSplash'])

<div id="{{ $id }}" class="fixed inset-0 z-[999] bg-[#1877F2] flex items-center justify-center splash-screen"
     style="padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);">
    <img src="{{ asset('images/icons/splash-logo.png') }}" alt="MetaSoft"
         width="96" height="96" class="splash-logo" style="filter: brightness(0) invert(1);">
</div>
