@php
    $loop6 = ['Brand joins', 'Creates profile', 'Gets nominated', 'Shares link', 'Audience visits', 'Discovers more brands'];
    // What free registration gives, stated only as strongly as the platform delivers it:
    // no certificate, messaging or guaranteed reach is offered because none exists.
    $benefits = [
        ['store', 'Your business profile', 'A public brand page with your logo, category, location and story: your business identity online.'],
        ['link', 'A link you can share', 'One permanent profile link for WhatsApp, Facebook, TikTok or your visiting card.'],
        ['search', 'Findable on Google', 'Approved profiles are public pages search engines can index. Google decides if and where they appear.'],
        ['eye', 'More visibility', 'People browsing the MetaSoft BD directory can discover your brand alongside others.'],
        ['users', 'Meet other businesses', 'Find brands and entrepreneurs in the directory and reach them through the contact details they publish.'],
        ['trophy', 'Free award entry', 'Take part in eligible MetaSoft BD award programmes at no cost, under each programme’s rules.'],
    ];
@endphp
<section id="join" class="scroll-mt-20 bg-white py-12 sm:py-16 lg:py-20">
    <div class="{{ $wrap }}">
        <div class="relative grid gap-8 overflow-hidden rounded-[28px] bg-[linear-gradient(135deg,#1769AA,#052B4C)] p-5 text-white sm:p-10 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:p-12">
            <div aria-hidden="true" class="bg-glow absolute -right-20 -top-20 h-80 w-80 bg-sky-400/15"></div>
            <div class="relative">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-[13px] font-semibold"><span class="h-2 w-2 rounded-full bg-gold"></span> Free for every brand</span>
                <h2 class="mt-4 text-[24px] font-extrabold leading-[1.15] tracking-tight sm:text-[32px] lg:text-[36px]">List your brand. Get discovered.</h2>
                <p class="mt-2 font-body text-base font-medium text-sky-200 sm:text-lg" lang="bn">আপনার ব্র্যান্ডের স্থায়ী ডিজিটাল পরিচয় তৈরি করুন</p>
                <p class="mt-3 max-w-lg text-[14.5px] leading-relaxed text-white/75 sm:text-base">A free business profile, a link to share, free award entry and, after our review, the blue Verified badge. <a href="#join-benefits" class="font-semibold text-white underline decoration-white/40 underline-offset-4 hover:decoration-white">See what you get</a></p>
                <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ $joinUrl }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gold px-6 py-3.5 text-[15px] font-bold text-night hover:brightness-105">
                        <x-plat.icon name="user-plus" class="w-[18px] h-[18px]" /> List your brand free
                    </a>
                    <a href="#brands" class="inline-flex items-center justify-center rounded-xl border border-white/30 px-6 py-3.5 text-[15px] font-bold hover:bg-white/10">Explore brands</a>
                </div>
                <p class="mt-3 text-xs text-white/55">Takes 2 minutes. Usually reviewed within 24 hours.</p>
            </div>

            {{-- Growth loop --}}
            <div class="relative mx-auto w-full max-w-[420px]">
                <div class="relative hidden aspect-square sm:block" aria-label="The MetaSoft BD growth loop">
                    <div class="absolute inset-[14%] rounded-full border-2 border-dashed border-white/25"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="max-w-[150px] text-center">
                            <p class="text-[10px] font-extrabold uppercase tracking-[0.18em] text-gold">The growth loop</p>
                            <p class="mt-1 text-[15px] font-bold leading-snug">Every brand brings the next one in.</p>
                        </div>
                    </div>
                    @foreach($loop6 as $i => $step)
                        @php $a = deg2rad(-90 + $i * 60); $x = 50 + 36 * cos($a); $y = 50 + 36 * sin($a); @endphp
                        <span class="absolute flex -translate-x-1/2 -translate-y-1/2 items-center gap-2 whitespace-nowrap rounded-full bg-white py-1.5 pl-1.5 pr-3 text-[12.5px] font-bold text-night shadow-xl" style="left: {{ round($x, 2) }}%; top: {{ round($y, 2) }}%">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-extrabold {{ $i === 5 ? 'bg-gold text-night' : 'bg-leaf text-white' }}">{{ $i + 1 }}</span>{{ $step }}
                        </span>
                    @endforeach
                </div>
                <ol class="grid grid-cols-2 gap-2 sm:hidden">
                    @foreach($loop6 as $i => $step)
                        <li class="flex items-center gap-2 rounded-xl bg-white/10 p-2.5 text-[13px] font-semibold"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-leaf text-[11px] font-extrabold">{{ $i + 1 }}</span>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>

            {{-- Free registration benefits: what each one means for the entrepreneur. --}}
            <div id="join-benefits" class="relative scroll-mt-24 lg:col-span-2">
                <h3 class="text-[13px] font-extrabold uppercase tracking-[0.14em] text-gold">What free registration gives you</h3>
                <ul class="mt-3 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3 lg:gap-3">
                    @foreach($benefits as [$icon, $title, $text])
                        <li class="flex gap-3 rounded-2xl bg-white/[0.07] p-3.5 ring-1 ring-white/12 sm:p-4">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-gold ring-1 ring-white/15"><x-plat.icon :name="$icon" class="w-[18px] h-[18px]" /></span>
                            <span class="min-w-0"><span class="block text-[15px] font-extrabold leading-snug">{{ $title }}</span><span class="mt-0.5 block text-[13.5px] leading-relaxed text-white/75">{{ $text }}</span></span>
                        </li>
                    @endforeach
                    {{-- The badge is set only by the MetaSoft BD team on approved brands (Super Admin → Brands → Verification, audited); registration never grants it. --}}
                    <li class="flex gap-3 rounded-2xl bg-white p-3.5 text-night ring-1 ring-white/20 sm:col-span-2 sm:p-4 lg:col-span-3 lg:items-center">
                        <x-plat.verified size="w-9 h-9" class="mt-0.5 lg:mt-0" />
                        <span class="min-w-0"><span class="block text-[15px] font-extrabold leading-snug">Blue Verified badge, after review</span><span class="mt-0.5 block text-[13.5px] leading-relaxed text-slate2">After your brand is approved, our team can review your business details. If they check out, the blue badge appears beside your brand name. It is never shown before that review.</span></span>
                    </li>
                </ul>
                <p class="mt-3 text-[12.5px] text-white/60">Registration is free. We don’t promise search rankings, customers or sales.</p>
            </div>
        </div>
    </div>
</section>
