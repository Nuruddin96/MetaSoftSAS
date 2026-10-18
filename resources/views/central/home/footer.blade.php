@php
    $cols = [
        'Platform' => [['Brands', '#brands'], ['Entrepreneurs', '#entrepreneurs'], ['Awards', '#awards'], ['Voting', '#voting'], ['Events', '#events'], ['Media', '#stories']],
        'For business' => [['List your brand', $joinUrl], ['Business Automation', route('automation')], ['Sponsorship', '#sponsors'], ['Refer & earn', route('affiliate.register')]],
        'Trust' => [['Award & voting rules', '#recognition'], ['What “Verified” means', '#verified'], ['How labels are earned', '#standards'], ['Privacy policy', null], ['Terms of use', null]],
    ];
    $cols = array_map(fn ($links) => array_values(array_filter($links, fn ($l) => ! in_array($l[1], $hiddenAnchors, true))), $cols);
@endphp
<footer class="bg-night text-white">
    <div class="{{ $wrap }} py-10 sm:py-14 lg:py-16">
        <div class="grid gap-9 lg:grid-cols-[1.2fr_2.2fr] lg:gap-12">
            <div class="max-w-sm">
                <a href="{{ url('/') }}" class="inline-flex items-center gap-2.5 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold"><x-ui.brand-mark /><span class="text-lg font-extrabold">MetaSoft BD</span></a>
                <p class="mt-4 text-[15px] leading-relaxed text-white/75">Bangladesh’s Entrepreneur & Brand Growth Network. Discover brands • Celebrate entrepreneurs • Vote • Grow.</p>
                <p class="mt-2 font-body text-[15px] text-white/65" lang="bn">বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম।</p>
                <div class="mt-5 flex gap-2.5">
                    @foreach([['facebook', 'Facebook'], ['youtube', 'YouTube'], ['tiktok', 'TikTok'], ['instagram', 'Instagram']] as [$p, $label])
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white/[0.08] text-white/80" title="{{ $label }} — coming soon">@include('partials.icon', ['platform' => $p, 'class' => 'w-4 h-4'])<span class="sr-only">{{ $label }}</span></span>
                    @endforeach
                    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="flex h-10 w-10 items-center justify-center rounded-full bg-white/[0.08] text-white/80 hover:bg-[#25D366] hover:text-white" aria-label="WhatsApp">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4'])</a>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-x-5 gap-y-8 sm:grid-cols-4 sm:gap-6">
                @foreach($cols as $title => $links)
                    <nav aria-label="{{ $title }}" class="min-w-0">
                        <p class="text-[13px] font-extrabold uppercase tracking-[0.12em] text-white">{{ $title }}</p>
                        <ul class="mt-2.5 grid gap-0.5">
                            @foreach($links as [$label, $href])
                                @if($href === null)
                                    <li class="flex min-h-10 items-center gap-1.5 text-[15px] text-white/50">{{ $label }} <span class="text-[11px] uppercase tracking-wider">soon</span></li>
                                @else
                                    <li><a href="{{ $href }}" @if(str_starts_with($href, 'https://wa.me')) target="_blank" rel="noopener" @endif
                                           class="inline-flex min-h-10 items-center rounded text-[15px] {{ $label === 'Business Automation' ? 'font-semibold text-sky-300' : 'text-white/80' }} hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">{{ $label }}@if($label === 'Business Automation')&nbsp;↗@endif</a></li>
                                @endif
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
                <div class="min-w-0">
                    <p class="text-[13px] font-extrabold uppercase tracking-[0.12em] text-white">Contact</p>
                    <ul class="mt-2.5 grid gap-0.5 text-[15px] text-white/80">
                        <li class="flex min-h-10 items-center">Dhaka, Bangladesh</li>
                        <li><a href="mailto:support@metasoftbd.com" class="inline-flex min-h-10 items-center break-all rounded hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">support@metasoftbd.com</a></li>
                        <li><a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center rounded hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold">WhatsApp us</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="mt-10 flex flex-col gap-2 border-t border-white/10 pt-6 text-[14px] text-white/70 sm:mt-12 sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} MetaSoft BD · Dhaka, Bangladesh</p>
            @if($preview)
                <p>Brands, people and figures shown are sample content for preview.</p>
            @endif
        </div>
    </div>
</footer>
