@php
    $cols = [
        'Platform' => [['Brands', '#brands'], ['Entrepreneurs', '#entrepreneurs'], ['Awards', '#awards'], ['Voting', '#voting'], ['Events', '#events'], ['Media', '#stories']],
        'For business' => [['List your brand', $joinUrl], ['Business Automation', route('automation')], ['Sponsorship', '#sponsors'], ['Refer & earn', route('affiliate.register')]],
        'Trust' => [['Award & voting rules', '#recognition'], ['How labels are earned', '#recognition'], ['Privacy policy', null], ['Terms of use', null]],
    ];
    $cols = array_map(fn ($links) => array_values(array_filter($links, fn ($l) => ! in_array($l[1], $hiddenAnchors, true))), $cols);
@endphp
<footer class="bg-night text-white">
    <div class="{{ $wrap }} py-14 lg:py-16">
        <div class="grid gap-10 lg:grid-cols-[1.3fr_2fr]">
            <div class="max-w-sm">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5"><x-ui.brand-mark /><span class="text-lg font-extrabold">MetaSoft BD</span></a>
                <p class="mt-4 text-sm leading-relaxed text-white/60">Bangladesh’s Entrepreneur & Brand Growth Network. Discover brands • Celebrate entrepreneurs • Vote • Grow.</p>
                <p class="mt-2 font-body text-sm text-white/50" lang="bn">বাংলাদেশের উদ্যোক্তা ও ব্র্যান্ডদের জাতীয় প্ল্যাটফর্ম।</p>
                <div class="mt-5 flex gap-2.5">
                    @foreach([['facebook', 'Facebook'], ['youtube', 'YouTube'], ['tiktok', 'TikTok'], ['instagram', 'Instagram']] as [$p, $label])
                        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/[0.08] text-white/80" title="{{ $label }} — coming soon">@include('partials.icon', ['platform' => $p, 'class' => 'w-4 h-4'])<span class="sr-only">{{ $label }}</span></span>
                    @endforeach
                    <a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/[0.08] text-white/80 hover:bg-[#25D366] hover:text-white" aria-label="WhatsApp">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-4 h-4'])</a>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-8 sm:grid-cols-4">
                @foreach($cols as $title => $links)
                    <nav aria-label="{{ $title }}">
                        <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-white">{{ $title }}</p>
                        <ul class="mt-4 space-y-2.5">
                            @foreach($links as [$label, $href])
                                @if($href === null)
                                    <li class="text-sm text-white/40">{{ $label }} <span class="text-[10px] uppercase tracking-wider">soon</span></li>
                                @else
                                    <li><a href="{{ $href }}" @if(str_starts_with($href, 'https://wa.me')) target="_blank" rel="noopener" @endif
                                           class="text-sm {{ $label === 'Business Automation' ? 'font-semibold text-emerald-300' : 'text-white/65' }} hover:text-white">{{ $label }}@if($label === 'Business Automation')&nbsp;↗@endif</a></li>
                                @endif
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
                <div>
                    <p class="text-xs font-extrabold uppercase tracking-[0.14em] text-white">Contact</p>
                    <ul class="mt-4 space-y-2.5 text-sm text-white/65">
                        <li>Dhaka, Bangladesh</li>
                        <li><a href="mailto:support@metasoftbd.com" class="break-all hover:text-white">support@metasoftbd.com</a></li>
                        <li><a href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener" class="hover:text-white">WhatsApp us</a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="mt-12 flex flex-col gap-3 border-t border-white/10 pt-6 text-xs text-white/50 sm:flex-row sm:items-center sm:justify-between">
            <p>© {{ date('Y') }} MetaSoft BD · Dhaka, Bangladesh</p>
            @if($preview)
                <p>Brands, people and figures shown are sample content for preview.</p>
            @endif
        </div>
    </div>
</footer>
