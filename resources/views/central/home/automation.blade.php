{{-- MetaSoft's existing SaaS, introduced without dominating the page. Links to /automation. --}}
<section id="automation" class="scroll-mt-20 bg-white py-16 sm:py-20">
    <div class="{{ $wrap }}">
        <div class="grid gap-8 rounded-3xl border border-[#DCE3F0] bg-[#F2F5FB] p-6 sm:p-9 lg:grid-cols-[0.9fr_1.1fr] lg:items-center">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-sky-500 text-white"><x-plat.icon name="zap" class="w-4 h-4" /></span>
                    <span class="text-sm font-extrabold text-navy">MetaSoft Automation</span>
                    <span class="rounded-full border border-[#DCE3F0] bg-white px-2 py-0.5 text-[11px] font-semibold text-slate2">A MetaSoft BD product</span>
                </div>
                <h2 class="mt-4 text-[26px] font-extrabold leading-tight tracking-tight sm:text-3xl">Grow your business with MetaSoft Automation.</h2>
                <p class="mt-3 text-[15px] leading-relaxed text-slate2">The software suite already powering Bangladeshi online businesses — website, POS, inventory, courier, inbox and marketing, managed from one panel.</p>
                <p class="mt-2 font-body text-[15px] text-slate2" lang="bn">ওয়েবসাইট, POS, কুরিয়ার ও অর্ডার — সব এক প্যানেলে।</p>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <a href="{{ route('automation') }}" class="inline-flex items-center gap-2 rounded-xl bg-navy px-5 py-3 text-[15px] font-bold text-white hover:bg-night">Explore MetaSoft Automation <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    <a href="{{ route('register') }}" class="text-sm font-bold text-navy hover:underline underline-offset-4">Start free trial</a>
                </div>
            </div>
            <ul class="grid gap-3 sm:grid-cols-2">
                @foreach($automation as $f)
                    <li class="flex items-center gap-3 rounded-2xl bg-white p-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#EEF2FA] text-navy"><x-plat.icon :name="$f['icon']" class="w-5 h-5" /></span>
                        <span><span class="block text-sm font-bold">{{ $f['title'] }}</span><span class="block text-xs text-slate2">{{ $f['desc'] }}</span></span>
                    </li>
                @endforeach
                <li class="flex items-center gap-3 rounded-2xl bg-navy p-4 text-white">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10"><x-plat.icon name="store" class="w-5 h-5" /></span>
                    <span><span class="block text-sm font-bold">7-day free trial</span><span class="block text-xs text-white/65">No card required</span></span>
                </li>
            </ul>
        </div>
    </div>
</section>
