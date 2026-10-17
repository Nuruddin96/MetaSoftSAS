{{--
    How the network works, in four honest steps — every step links to a
    flow that exists today (directory, List Your Brand, owner dashboard,
    the Verified explainer). No figures, no promises about timing.
--}}
@php
    $steps = [
        ['store', 'Explore brands', 'Browse approved brand profiles by category and district.', 'Browse brands', $realBrands ? route('brands.index') : '#brands'],
        ['user-plus', 'List your brand free', 'Add your logo, category and contact details in a few minutes.', 'List your brand', $joinUrl],
        ['shield', 'Reviewed by our team', 'Every listing is checked before it goes public. Track its status in your dashboard.', 'Owner login', route('owner.login')],
        ['award', 'Get verified & recognised', 'Approved brands can earn the Verified badge and be nominated for the awards.', 'What Verified means', '#verified'],
    ];
@endphp
<section id="how" class="scroll-mt-20 border-b border-hair bg-white py-10 sm:py-14" aria-labelledby="howTitle">
    <div class="{{ $wrap }}">
        <div class="max-w-2xl">
            <p class="font-body text-[13px] font-semibold text-slate2" lang="bn">কীভাবে কাজ করে</p>
            <h2 id="howTitle" class="font-plat text-2xl font-extrabold tracking-tight text-night sm:text-3xl">How MetaSoft BD works</h2>
            <p class="mt-1.5 text-[14.5px] text-slate2 sm:text-base">A free, reviewed directory of Bangladeshi brands — and the home of the {{ config('platform.award_name') }}.</p>
        </div>

        {{-- Phones: compact rows (icon beside text). From sm: cards, 2 then 4 across. --}}
        <ol class="mt-5 grid gap-2.5 sm:mt-6 sm:grid-cols-2 sm:gap-3 lg:grid-cols-4 lg:gap-4">
            @foreach($steps as $i => [$icon, $title, $text, $cta, $href])
                <li class="flex gap-3 rounded-2xl border border-hair bg-cloud p-3.5 sm:flex-col sm:gap-0 sm:p-5">
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-night text-white"><x-plat.icon :name="$icon" class="w-5 h-5" /></span>
                        <span class="hidden text-xs font-extrabold uppercase tracking-[0.14em] text-leaf sm:inline">Step {{ $i + 1 }}</span>
                    </div>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <h3 class="text-[15px] font-extrabold leading-snug text-night sm:mt-3 sm:text-[16px]"><span class="text-leaf sm:hidden">{{ $i + 1 }}. </span>{{ $title }}</h3>
                        <p class="mt-0.5 text-[13px] leading-relaxed text-slate2 sm:mt-1 sm:text-[13.5px]">{{ $text }}</p>
                        <a href="{{ $href }}" class="mt-auto inline-flex items-center gap-1 pt-1.5 text-sm font-bold text-leaf hover:underline underline-offset-4 sm:pt-3">{{ $cta }} <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</section>
