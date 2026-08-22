<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'শুরু করুন') — {{ app('currentTenant')->store_name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Noto+Serif+Bengali:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <meta name="theme-color" content="#128155">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/icons/favicon-32.png') }}">
    <script>window.__csrf = @js(csrf_token());</script>
</head>
<body class="font-body bg-paper text-ink antialiased min-h-screen">

@php
    $stepIndex = array_search($step, $steps, true);
    $totalSteps = count($steps) - 1; // 'complete' is the celebration screen, not counted as a step to finish
    $stepNumber = min($stepIndex + 1, $totalSteps);
@endphp

<div class="min-h-screen flex flex-col">
    <header class="px-4 py-5 sm:py-6">
        <div class="max-w-lg mx-auto">
            <p class="font-disp font-bold text-lg text-center mb-4">{{ $tenant->store_name }}</p>

            @if ($step !== 'complete')
                <div class="flex items-center justify-between text-xs text-mute mb-1.5">
                    <span>ধাপ {{ $stepNumber }} / {{ $totalSteps }}</span>
                </div>
                <div class="h-1.5 rounded-pill bg-ink/5 overflow-hidden">
                    <div class="h-full bg-leaf rounded-pill transition-all" style="width: {{ round($stepNumber / $totalSteps * 100) }}%"></div>
                </div>
            @endif
        </div>
    </header>

    <main class="flex-1 flex items-start sm:items-center justify-center px-4 pb-10">
        <div class="w-full max-w-lg">
            @if (session('error'))
                <p class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">{{ session('error') }}</p>
            @endif

            <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-6 sm:p-8">
                @yield('content')
            </div>
        </div>
    </main>
</div>

<script>document.addEventListener('DOMContentLoaded', () => { if (window.lucide) lucide.createIcons(); });</script>
</body>
</html>
