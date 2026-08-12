{{--
    Branded full-page loading state — shown on initial panel load (see
    layouts/panel.blade.php, #appSplash) and reusable anywhere else a
    genuine full-page loading moment is needed later. Uses the official
    MetaSoft logo PNG (public/images/icons/icon-512.png, generated from
    the source SVG — see PwaController's docblock for why a PNG rather
    than the raw SVG: the source file's own filters/masks are heavier to
    inline than a pre-rasterized transparent PNG for a moment that must
    disappear within a couple hundred ms).

    Not for per-button/per-form loading — that's the existing .btn-loading
    + .btn-spinner mechanism in resources/js/app.js, deliberately left
    alone (a spinner, not this logo, is correct for a small tap target).
--}}
@props(['id' => 'appSplash'])

<div id="{{ $id }}" class="fixed inset-0 z-[999] bg-paper flex flex-col items-center justify-center gap-4 splash-screen">
    <img src="{{ asset('images/icons/icon-192.png') }}" alt="MetaSoft" width="72" height="72" class="splash-logo">
    <p class="font-disp font-bold text-ink/70 text-sm tracking-wide splash-label">MetaSoft SaaS</p>
    <span class="splash-dot"></span>
</div>
