<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', app('currentTenant')->store_name)</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- PWA: tenant-specific identity, NOT the central manifest/service
         worker. This page (tenant panel login) is reached unauthenticated,
         so both endpoints must stay public — see the 'panel/manifest.json'
         and 'panel/sw.js' route comments in routes/web.php for why that's
         safe. Using the tenant routes here (rather than falling back to
         layouts.central) is what makes "Add to Home Screen" from the login
         page install this tenant's panel, not the central marketing site. --}}
    <link rel="manifest" href="{{ route('tenant.pwa.manifest') }}">
    <meta name="theme-color" content="#128155">
    <meta name="background-color" content="#F4F2EA">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icons/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/icons/favicon-16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/icons/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="MetaSoft">
    <script>window.__swUrl = @js(route('tenant.pwa.sw'));</script>
    <style>
        html { scroll-behavior: smooth; }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="font-body bg-paper text-ink antialiased">
    <a href="#main-content"
       class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[999] focus:px-4 focus:py-2.5 focus:rounded-btn focus:bg-ink focus:text-white focus:font-semibold focus:outline-none focus:ring-2 focus:ring-leaf focus:ring-offset-2">
        স্কিপ করে মূল কনটেন্টে যান
    </a>
    @yield('content')
</body>
</html>
