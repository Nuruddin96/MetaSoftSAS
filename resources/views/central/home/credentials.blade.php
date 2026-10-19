{{--
    Concept C "Company credentials": the registration certificate shown framed,
    with the issuer beside it. Renders only once the scan exists
    (public/images/home/certificate.*, HomeMedia::certificate()) — the page
    never claims a document it can't show. Registration is not presented as
    a government endorsement.
--}}
@if($media['certificate'])
<section id="credentials" class="scroll-mt-20 bg-cloud py-9 sm:py-14" aria-labelledby="credentialsTitle">
    <div class="{{ $wrap }} grid items-center gap-7 md:grid-cols-[minmax(0,0.85fr)_minmax(0,1fr)] lg:gap-14">
        <div class="mx-auto w-full max-w-[300px] md:max-w-[340px]">
            <a href="{{ $media['certificate'] }}" target="_blank" rel="noopener" class="group block w-full rounded-[18px] bg-[linear-gradient(145deg,#ffffff,#E7F0FA)] p-3 shadow-[0_24px_50px_-26px_rgba(11,37,69,0.55)] ring-1 ring-hair focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf sm:p-4">
                <span class="block overflow-hidden rounded-md bg-white p-1.5 ring-1 ring-hair"><img src="{{ $media['certificate'] }}" alt="MetaSoft BD company registration certificate" loading="lazy" class="aspect-[1/1.414] w-full rounded-sm object-cover object-top"></span>
                <span class="mt-2.5 flex items-center justify-center gap-1.5 text-[13px] font-bold text-leaf group-hover:underline"><x-plat.icon name="eye" class="w-4 h-4" /> Open the certificate</span>
            </a>
        </div>
        <div class="min-w-0">
            <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Company credentials</p>
            <h2 id="credentialsTitle" class="{{ $h2 }}">Officially registered. Openly shown.</h2>
            <p class="mt-2 text-[14.5px] leading-relaxed text-slate2">Before you trust us with your business, you can read our registration certificate yourself.</p>
            <dl class="mt-4 text-[13.5px]">
                <div class="rounded-xl bg-white px-3.5 py-2.5 ring-1 ring-hair"><dt class="text-[12px] text-slate2">Issued by</dt><dd class="font-bold">Ministry of Commerce, Government of Bangladesh</dd></div>
            </dl>
            <p class="mt-3 text-[12.5px] leading-relaxed text-slate2">Registration shows MetaSoft BD is a legally registered business. It is not a government endorsement of our services or of any listed brand.</p>
        </div>
    </div>
</section>
@endif
