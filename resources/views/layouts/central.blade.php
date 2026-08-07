<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'MetaSoft BD — বিজনেস অটোমেশন')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html { scroll-behavior: smooth; }
        /* signature: CSS barcode stripe */
        .barcode {
            background-image: repeating-linear-gradient(90deg,
                #132A21 0 2px, transparent 2px 5px,
                #132A21 5px 6px, transparent 6px 11px,
                #132A21 11px 14px, transparent 14px 16px,
                #132A21 16px 17px, transparent 17px 22px);
        }
        .receipt-edge {
            -webkit-mask-image: linear-gradient(#000 0 0), radial-gradient(circle at 8px 100%, transparent 8px, #000 8px);
            mask: radial-gradient(circle 7px at 10px 100%, transparent 97%, #000) -10px 0/20px 100% repeat-x;
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body class="font-body bg-paper text-ink antialiased">
    @yield('content')
</body>
</html>
