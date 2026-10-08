<section id="voting" class="scroll-mt-20 bg-white py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="Live voting" bn="ভোট দিন"
            title="Vote for the brands you love."
            :sub="$votingNote"
            link="All categories" href="#recognition" />

        <div class="-mx-4 mt-8 overflow-x-auto px-4 pb-1 [scrollbar-width:none] sm:mx-0 sm:px-0" >
            <div class="flex w-max gap-2" role="tablist" aria-label="Voting categories" data-tabs="voting">
                @foreach($voting as $key => $cat)
                    <button type="button" role="tab" id="vtab-{{ $key }}" aria-controls="vpanel-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="{{ $key }}"
                            class="whitespace-nowrap rounded-full px-4 py-2.5 text-[13.5px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf aria-selected:bg-night aria-selected:text-white aria-[selected=false]:bg-cloud aria-[selected=false]:text-night aria-[selected=false]:hover:bg-hair">
                        {{ $cat['label'] }}
                    </button>
                @endforeach
            </div>
        </div>

        @foreach($voting as $key => $cat)
            <div id="vpanel-{{ $key }}" role="tabpanel" aria-labelledby="vtab-{{ $key }}" data-panel="voting:{{ $key }}" @class(['mt-6', 'hidden' => ! $loop->first])>
                <div class="mb-4 flex items-center justify-between text-[13px] text-slate2">
                    @php $heading = $cat['heading'] ?? $cat['label'].' of the Year 2026'; $counts = $cat['show_counts'] ?? true; @endphp
                    <span><b class="text-night">{{ $heading }}</b>@if($counts) · {{ number_format($cat['total']) }} votes so far @endif</span>
                    <span class="hidden items-center gap-1.5 sm:flex"><span class="h-2 w-2 animate-pulse rounded-full bg-flag"></span> Live</span>
                </div>
                <div class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-2 [scrollbar-width:none] sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 lg:grid-cols-4">
                    @foreach($cat['nominees'] as $n)
                        <div class="w-[78%] shrink-0 snap-start sm:w-auto">
                            <x-plat.nominee-card :nominee="$n" :category="$heading" :show-counts="$counts" />
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Growth loop: nominees share their voting link --}}
        <div class="mt-8 flex flex-col gap-5 rounded-3xl bg-mint p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-leaf text-white"><x-plat.icon name="share" class="w-5 h-5" /></span>
                <div>
                    <p class="text-base font-bold">Nominated? Share your voting link with customers.</p>
                    <p class="mt-0.5 text-[13.5px] text-slate2">Every share brings new people to discover your brand — and every other brand on MetaSoft BD.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" data-copy="{{ url('/') }}#voting" class="inline-flex items-center gap-2 rounded-xl border border-hair bg-white px-3.5 py-2.5 text-[13px] font-semibold text-slate2 hover:border-leaf">
                    <span class="max-w-[190px] truncate">metasoftbd.com/vote/your-brand</span> <span class="font-bold text-leaf">Copy</span>
                </button>
                <a href="https://wa.me/?text={{ rawurlencode('Vote for your favourite Bangladeshi brands on MetaSoft BD: '.url('/').'#voting') }}" target="_blank" rel="noopener" class="flex h-10 w-10 items-center justify-center rounded-full bg-[#25D366] text-white hover:brightness-95" aria-label="Share on WhatsApp">@include('partials.icon', ['platform' => 'whatsapp', 'class' => 'w-5 h-5'])</a>
                <a href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode(url('/').'#voting') }}" target="_blank" rel="noopener" class="flex h-10 w-10 items-center justify-center rounded-full bg-[#1877F2] text-white hover:brightness-95" aria-label="Share on Facebook">@include('partials.icon', ['platform' => 'facebook', 'class' => 'w-5 h-5'])</a>
                <button type="button" data-share data-share-title="{{ config('platform.award_name') }}" data-share-url="{{ url('/') }}#voting" class="flex h-10 w-10 items-center justify-center rounded-full bg-night text-white hover:bg-navy" aria-label="Share on TikTok or other apps">@include('partials.icon', ['platform' => 'tiktok', 'class' => 'w-[18px] h-[18px]'])</button>
            </div>
        </div>
        <p class="mt-4 flex items-start gap-2 text-[13px] text-slate2"><x-plat.icon name="lock" class="mt-0.5 w-4 h-4 shrink-0" /> Votes are verified by phone OTP and audited for fraud before results are published. Sponsors and paid features never add votes.</p>
    </div>
</section>
