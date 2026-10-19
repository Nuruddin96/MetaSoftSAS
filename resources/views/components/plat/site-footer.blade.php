{{--
    Homepage-style navy footer for platform pages (same colours, type and
    layout as central/home/footer.blade.php), used by the registration page.

    "Registered with the Ministry of Commerce, Bangladesh" is shown only while
    the DBID certificate that backs it is published (HomeMedia::certificate():
    RJSC, Ministry of Commerce, DBID 638491954) and links to it. No government
    emblem is used: there is no record of permission to use it, and the line
    must not read as a government endorsement.
--}}
@php
    $home = url('/');
    $wrap = 'mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8';
    $certificate = \App\Support\Home\HomeMedia::certificate();
    $whatsapp = preg_replace('/\D/', '', (string) config('payment.support_whatsapp'));
    $cols = [
        'Explore' => [
            ['Brand directory', route('brands.index')],
            ['Verified badge', $home.'#verified'],
            ['Awards & award rules', $home.'#awards'],
            ['Business Automation', route('automation')],
        ],
        'For businesses' => [
            ['Create free profile', route('owner.register')],
            ['Brand owner login', route('owner.login')],
            ['Entrepreneur community', $home.'#community'],
            ['Refer & earn', route('affiliate.register')],
        ],
        'Contact' => array_values(array_filter([
            $certificate ? ['Company credentials', $home.'#credentials'] : null,
            ['support@metasoftbd.com', 'mailto:support@metasoftbd.com'],
            $whatsapp ? ['WhatsApp us', 'https://wa.me/'.$whatsapp] : null,
        ])),
    ];
    $link = 'inline-flex min-h-10 items-center rounded text-[15px] text-white/80 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold sm:min-h-9';
@endphp
<footer class="bg-night text-white">
    <div class="{{ $wrap }} grid gap-8 py-10 sm:py-12 md:grid-cols-[1.4fr_repeat(3,minmax(0,1fr))] md:gap-6 lg:gap-10">
        <div class="max-w-sm">
            <a href="{{ $home }}" class="inline-flex items-center gap-2.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">
                <x-ui.brand-mark />
                <span class="leading-none"><span class="block text-[18px] font-extrabold tracking-tight">MetaSoft BD</span><span class="mt-1 block text-[10.5px] font-bold uppercase tracking-[0.16em] text-gold">Brand & Entrepreneur Network</span></span>
            </a>
            <p class="mt-4 text-[15px] leading-relaxed text-white/75">Bangladesh’s entrepreneur and brand growth network.</p>
            <p class="mt-1 font-body text-[15px] text-white/65" lang="bn">বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম।</p>
            @if($certificate)
                <a href="{{ $home }}#credentials" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-lg border border-white/15 bg-white/[0.06] px-3 text-[14px] font-semibold text-white/90 hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">
                    <x-plat.icon name="shield" class="w-4 h-4 shrink-0 text-gold" /> Registered with the Ministry of Commerce, Bangladesh
                </a>
            @endif
        </div>
        <div class="grid grid-cols-2 gap-x-4 gap-y-7 sm:grid-cols-3 md:col-span-3 md:gap-6 lg:gap-10">
            @foreach($cols as $title => $links)
                <nav aria-label="{{ $title }}" @class(['min-w-0', 'col-span-2 sm:col-span-1' => $loop->last])>
                    <h2 class="text-[13px] font-extrabold uppercase tracking-[0.12em] text-white">{{ $title }}</h2>
                    <ul class="mt-2.5 grid gap-0.5 sm:mt-3">
                        @foreach($links as [$label, $href])
                            <li><a href="{{ $href }}" @if(str_starts_with($href, 'https://wa.me')) target="_blank" rel="noopener" @endif class="{{ $link }} {{ str_contains($label, '@') ? 'break-all' : '' }} {{ $label === 'Business Automation' ? 'font-semibold text-sky-300' : '' }}">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="{{ $wrap }} py-5 text-[14px] text-white/70">
            <p>© {{ date('Y') }} MetaSoft BD · Dhaka, Bangladesh. All rights reserved.</p>
        </div>
    </div>
</footer>
