{{--
    What every label means — stated plainly and only as strongly as the code
    enforces it. Verified: granted by Super Admin (BrandController::verification),
    with the evidence actually reviewed listed on each profile. Votes: phone
    numbers are NOT OTP-verified (VoteService) — say so. Sponsored: paid,
    always labelled, never touches results. #sponsors lives here so header and
    footer "Sponsorship" links keep working.
--}}
<section id="standards" class="scroll-mt-20 bg-cloud py-12 sm:py-16" aria-labelledby="trustTitle">
    <div class="{{ $wrap }}">
        <div class="max-w-2xl">
            <p class="font-body text-[13px] font-semibold text-slate2" lang="bn">টাকা দিয়ে অ্যাওয়ার্ড কেনা যায় না।</p>
            <h2 id="trustTitle" class="font-plat text-2xl font-extrabold tracking-tight text-night sm:text-3xl">Recognition you can trust — <span class="text-leaf">never for sale.</span></h2>
            <p class="mt-1.5 text-[14.5px] text-slate2 sm:text-base">What each label on MetaSoft BD means, and what it doesn’t.</p>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <article id="verified" class="scroll-mt-24 rounded-[20px] border border-hair bg-white p-5 sm:p-6 lg:row-span-2">
                <div class="flex items-center gap-3">
                    <x-plat.verified size="w-9 h-9" />
                    <h3 class="text-lg font-extrabold text-night">The Verified badge</h3>
                </div>
                <p class="mt-3 text-[14px] leading-relaxed text-slate2">Granted only by the MetaSoft BD team after reviewing an approved brand. A brand cannot add it to its own profile, and it can be removed if details stop checking out.</p>
                <p class="mt-3 text-[13px] font-bold text-night">A review can include:</p>
                <ul class="mt-1.5 space-y-1.5 text-[13.5px] text-slate2">
                    @foreach($verificationEvidence as $item)
                        <li class="flex items-start gap-2"><x-plat.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-leaf" /> {{ $item }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 rounded-xl bg-cloud px-3.5 py-2.5 text-[13px] text-slate2">Each verified profile lists exactly what was reviewed for that brand. Verification is not an award, a vote result or a paid placement.</p>
                {{-- How the seal looks beside a verified brand's name: an illustration only, not a real brand. --}}
                <div class="mt-4 rounded-xl border border-dashed border-hair p-3" aria-label="Example of a verified brand">
                    <p class="text-[11px] font-extrabold uppercase tracking-[0.12em] text-slate2">Example · how it looks</p>
                    <div class="mt-2 flex min-w-0 items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[linear-gradient(135deg,#1769AA,#073B66)] text-[15px] font-extrabold text-white" aria-hidden="true">BN</span>
                        <div class="min-w-0">
                            <p class="flex min-w-0 items-center gap-1.5"><span class="truncate text-[15.5px] font-extrabold text-night">Brand name</span> <x-plat.verified size="w-5 h-5" /></p>
                            <p class="mt-0.5 inline-flex items-center gap-1.5 rounded-full bg-[#E8F1FC] px-2.5 py-0.5 text-[12px] font-bold text-[#1769AA]"><x-plat.verified size="w-3.5 h-3.5" /> MetaSoft BD Verified</p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="rounded-[20px] border border-hair bg-white p-5 sm:p-6">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-mint text-leaf"><x-plat.icon name="vote" class="w-5 h-5" /></span>
                    <h3 class="text-lg font-extrabold text-night">Public votes</h3>
                </div>
                <p class="mt-3 text-[14px] leading-relaxed text-slate2">Each mobile number can vote once per category in a voting period — some programmes allow one vote in total. Numbers are <strong class="text-night">not verified by SMS or OTP</strong>; votes are screened for repeated numbers, devices and unusual patterns, and flagged votes can be removed by our team.</p>
            </article>

            <div class="grid gap-4 sm:grid-cols-2">
                <article class="rounded-[20px] border border-hair bg-white p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-mint text-leaf"><x-plat.icon name="star" class="w-4 h-4" /></span>
                        <h3 class="text-base font-extrabold text-night">Editor’s Pick</h3>
                    </div>
                    <p class="mt-2 text-[13.5px] leading-relaxed text-slate2">Chosen by MetaSoft BD editors. Never sold.</p>
                </article>
                <article @if($home['show_sponsors']) id="sponsors" @endif class="scroll-mt-24 rounded-[20px] border border-dashed border-slate-300 bg-white p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate2">Sponsored</span>
                        <h3 class="text-base font-extrabold text-night">Paid placement</h3>
                    </div>
                    <p class="mt-2 text-[13.5px] leading-relaxed text-slate2">Always labelled “Sponsored”. Never adds votes, affects jury decisions or grants verification.</p>
                    @if($home['show_sponsors'])
                        <p class="mt-2 text-[13px] text-slate2">No partners have been announced yet.</p>
                        <a href="{{ $wa('I am interested in sponsoring MetaSoft BD awards / events.') }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-sm font-bold text-leaf hover:underline underline-offset-4">Partner with us <x-plat.icon name="arrow-right" class="w-4 h-4" /></a>
                    @endif
                </article>
            </div>
        </div>
    </div>
</section>
