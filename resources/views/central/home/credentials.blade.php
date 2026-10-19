{{--
    Concept C "Company credentials": MetaSoft BD's Certificate of Digital
    Business Identity (DBID), shown framed, with the facts printed on it.
    Renders only once the scan exists (HomeMedia::certificate()) — the page
    never claims a document it can't show. The published copy hides the
    business address and personal email; the DBID number and QR code are
    enough to verify it with RJSC. Not presented as a government endorsement.
--}}
@if($media['certificate'])
<section id="credentials" class="scroll-mt-20 bg-cloud py-9 sm:py-14" aria-labelledby="credentialsTitle">
    <div class="{{ $wrap }} grid items-center gap-7 md:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)] lg:gap-14">
        <div class="mx-auto w-full max-w-[300px] md:max-w-[340px]">
            <a href="{{ $media['certificate'] }}" target="_blank" rel="noopener" class="group block w-full rounded-[18px] bg-[linear-gradient(145deg,#ffffff,#E7F0FA)] p-3 shadow-[0_24px_50px_-26px_rgba(11,37,69,0.55)] ring-1 ring-hair focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:p-4">
                <span class="block overflow-hidden rounded-md bg-white p-1.5 ring-1 ring-hair"><img src="{{ $media['certificate'] }}" alt="MetaSoft BD Certificate of Digital Business Identity (DBID) issued by RJSC, Ministry of Commerce" loading="lazy" class="aspect-[589/839] w-full rounded-sm object-contain"></span>
                <span class="mt-2.5 flex items-center justify-center gap-1.5 text-[13px] font-bold text-leaf group-hover:underline"><x-plat.icon name="eye" class="w-4 h-4" /> Open the certificate</span>
            </a>
        </div>
        <div class="min-w-0">
            <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Company credentials</p>
            <h2 id="credentialsTitle" class="{{ $h2 }}">Officially registered. Openly shown.</h2>
            <p class="mt-2 text-[14.5px] leading-relaxed text-slate2">MetaSoft BD is registered under the Digital Business Identity (DBID) Guidelines 2022. Before you trust us with your business, you can read the certificate yourself.</p>
            <dl class="mt-4 grid grid-cols-2 gap-2.5 text-[13.5px]">
                <div class="col-span-2 rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">Certificate</dt><dd class="font-bold">Certificate of Digital Business Identity (DBID)</dd></div>
                <div class="col-span-2 rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">Issued by</dt><dd class="font-bold">Office of the Registrar of Joint Stock Companies and Firms (RJSC), Ministry of Commerce, Government of Bangladesh</dd></div>
                <div class="rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">DBID no.</dt><dd class="font-bold tabular-nums">638491954</dd></div>
                <div class="rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">Registered on</dt><dd class="font-bold">11 March 2025</dd></div>
                <div class="rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">Business type</dt><dd class="font-bold">Online Marketing</dd></div>
                <div class="rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">Ownership</dt><dd class="font-bold">Sole Proprietorship</dd></div>
            </dl>
            <p class="mt-3 text-[12.5px] leading-relaxed text-slate2">The DBID number and the QR code on the certificate can be checked with RJSC. The business address and personal email are hidden on this copy for privacy. Registration shows MetaSoft BD is a legally registered business; it is not a government endorsement of our services or of any listed brand.</p>
        </div>
    </div>
</section>
@endif
