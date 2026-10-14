@php
    $loop6 = ['Brand joins', 'Creates profile', 'Gets nominated', 'Shares link', 'Audience visits', 'Discovers more brands'];
@endphp
<section id="join" class="scroll-mt-20 bg-white py-12 sm:py-16 lg:py-20">
    <div class="{{ $wrap }}">
        <div class="relative grid gap-8 overflow-hidden rounded-[28px] bg-[linear-gradient(135deg,#1769AA,#052B4C)] p-5 text-white sm:p-10 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:p-12">
            <div aria-hidden="true" class="bg-glow absolute -right-20 -top-20 h-80 w-80 bg-sky-400/15"></div>
            <div class="relative">
                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-[13px] font-semibold"><span class="h-2 w-2 rounded-full bg-gold"></span> Free for every brand</span>
                <h2 class="mt-4 text-[24px] font-extrabold leading-[1.15] tracking-tight sm:text-[32px] lg:text-[36px]">List your brand. Get discovered.</h2>
                <p class="mt-2 font-body text-base font-medium text-sky-200 sm:text-lg" lang="bn">আপনার ব্র্যান্ডের স্থায়ী ডিজিটাল পরিচয় তৈরি করুন</p>
                <p class="mt-3 max-w-lg text-[14.5px] leading-relaxed text-white/75 sm:text-base">A free profile, award nominations and new customers.</p>
                <ul class="mt-5 space-y-2.5">
                    @foreach(['Your own permanent profile link', 'Free award nominations', 'Share kits for WhatsApp, Facebook & TikTok', 'Customers across Bangladesh'] as $item)
                        <li class="flex items-start gap-3 text-[15px]"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-gold text-night"><x-plat.icon name="check" class="w-3 h-3" stroke="3.2" /></span>{{ $item }}</li>
                    @endforeach
                </ul>
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
        </div>
    </div>
</section>
