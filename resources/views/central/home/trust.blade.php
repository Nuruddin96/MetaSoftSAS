{{-- Anti-pay-to-win promise, stated up front --}}
<section class="border-b border-hair bg-white" aria-labelledby="trustTitle">
    <div class="{{ $wrap }} py-8 lg:py-10">
        <div class="grid gap-6 lg:grid-cols-[260px_1fr] lg:items-center">
            <div>
                <h2 id="trustTitle" class="text-lg font-extrabold leading-snug tracking-tight">Recognition you can trust — <span class="text-leaf">never for sale.</span></h2>
                <p class="mt-1 font-body text-sm text-slate2" lang="bn">টাকা দিয়ে অ্যাওয়ার্ড কেনা যায় না।</p>
            </div>
            <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach([
                    ['user-plus', 'Free to nominate', 'Every brand can apply'],
                    ['phone', 'Phone-verified votes', 'One person, one vote a day'],
                    ['scale', 'Independent jury', 'Named members, published scores'],
                    ['info', 'Sponsored = labelled', 'Paid never touches results'],
                ] as [$icon, $title, $desc])
                    <li class="flex items-start gap-3 rounded-2xl bg-cloud p-3.5 sm:p-4">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-leaf shadow-sm"><x-plat.icon :name="$icon" class="w-[18px] h-[18px]" /></span>
                        <span class="min-w-0"><span class="block text-sm font-bold leading-snug">{{ $title }}</span><span class="block text-xs leading-snug text-slate2">{{ $desc }}</span></span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
