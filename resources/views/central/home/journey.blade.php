{{-- Concept C "Our journey": a slow continuous carousel of real programme photos (home.js [data-marquee]). Hidden until photos exist. --}}
@if($media['journey'])
<section id="impact" class="scroll-mt-20 bg-white py-9 sm:py-14" aria-labelledby="journeyTitle">
    <div class="{{ $wrap }}">
        <div class="flex items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Our journey</p>
                <h2 id="journeyTitle" class="{{ $h2 }}">Moments from our programmes</h2>
            </div>
            @include('central.home.marquee-controls', ['id' => 'journeyRow'])
        </div>
        <div id="journeyRow" data-marquee="16" class="-mx-4 mt-5 flex gap-3 px-4 sm:mx-0 sm:px-0 lg:gap-4" aria-label="Programme photos">
            @foreach($media['journey'] as $p)
                <figure class="relative w-[72vw] max-w-[320px] shrink-0 overflow-hidden rounded-xl sm:w-[320px] sm:max-w-none lg:w-[360px]">
                    @include('central.home.photo', ['p' => $p, 'ratio' => '4/3', 'class' => 'rounded-xl'])
                </figure>
            @endforeach
        </div>
    </div>
</section>
@endif
