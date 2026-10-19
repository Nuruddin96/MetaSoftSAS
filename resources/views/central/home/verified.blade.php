{{--
    Concept C "The blue Verified badge": what it means — stated only as
    strongly as the code enforces it (Super Admin grants it on approved
    brands, listing the evidence reviewed; Brand::VERIFICATION_EVIDENCE) —
    and how it looks in the directory and on a profile. Both examples are
    labelled; neither is a real brand.
--}}
@php
    $example = ['name' => 'Your Brand Name', 'initials' => 'YB', 'from' => '#1769AA', 'to' => '#073B66', 'logo' => null, 'cover' => null,
        'category' => 'Your category', 'district' => 'Your district', 'verified' => true, 'tag' => null, 'sample' => false, 'url' => '#verified'];
@endphp
<section id="verified" class="scroll-mt-20 border-y border-hair bg-cloud py-9 sm:py-12" aria-labelledby="verifiedTitle">
    <div class="{{ $wrap }} grid items-center gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] md:gap-10">
        <div class="min-w-0">
            <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Trust signal</p>
            <h2 id="verifiedTitle" class="{{ $h2 }} flex items-center gap-2.5">The blue Verified badge <x-plat.verified size="w-7 h-7 sm:w-8 sm:h-8" /></h2>
            <p class="mt-2 text-[14.5px] leading-relaxed text-slate2">Granted only by the MetaSoft BD team after reviewing an approved brand. A brand cannot add it to its own profile, and it is not an award, a vote result or a paid placement. Not every listed brand has it.</p>
            <p class="mt-3 text-[13px] font-bold text-night">A review can include:</p>
            <ul class="mt-1.5 grid gap-1.5 text-[13.5px] text-slate2 sm:grid-cols-2">
                @foreach($verificationEvidence as $item)
                    <li class="flex items-start gap-2"><x-plat.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-leaf" /> {{ $item }}</li>
                @endforeach
            </ul>
            <p class="mt-3 text-[13px] text-slate2">Each verified profile lists exactly what was reviewed for that brand.</p>
        </div>
        <div class="grid min-w-0 grid-cols-2 items-start gap-3 sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)] sm:gap-4" aria-label="Example of a verified brand">
            <figure class="min-w-0">
                <figcaption class="mb-1.5 text-[11px] font-extrabold uppercase tracking-[0.12em] text-slate2">In the directory</figcaption>
                <x-plat.showcase-card :brand="$example" />
            </figure>
            <figure class="min-w-0">
                <figcaption class="mb-1.5 text-[11px] font-extrabold uppercase tracking-[0.12em] text-slate2">On the profile</figcaption>
                <div class="overflow-hidden rounded-xl border border-hair bg-white">
                    <div class="h-12 bg-[linear-gradient(135deg,#1769AA,#073B66)] sm:h-16"></div>
                    <div class="px-3 pb-3 sm:px-4 sm:pb-4">
                        <x-plat.logo :initials="$example['initials']" :from="$example['from']" :to="$example['to']" :size="52" class="relative -mt-7 border border-hair shadow-sm ring-[3px] ring-white" />
                        <p class="mt-1.5 flex min-w-0 items-center gap-1.5"><span class="truncate text-[14.5px] font-extrabold sm:text-[18px]">{{ $example['name'] }}</span> <x-plat.verified size="w-5 h-5 sm:w-6 sm:h-6" /></p>
                        <p class="truncate text-[12.5px] text-slate2">{{ $example['category'] }} · {{ $example['district'] }}</p>
                        <p class="mt-2 inline-flex max-w-full items-center gap-1.5 rounded-full bg-[#E8F1FC] px-2.5 py-1 text-[12px] font-bold text-[#1769AA]"><x-plat.verified size="w-3.5 h-3.5" /> <span class="truncate">MetaSoft BD Verified</span></p>
                        <p class="mt-2"><span class="rounded bg-amber-100 px-1.5 text-[10.5px] font-bold leading-[18px] text-amber-800">Example</span> <span class="text-[11.5px] text-slate2">not a real brand</span></p>
                    </div>
                </div>
            </figure>
        </div>
    </div>
</section>
