{{-- Concept C photo strip overlapping the hero's foot: real programme photos, slowly scrolling (home.js [data-marquee]). Outside the hero so its overflow clip can't cut them. --}}
@if($media['moments'])
<div class="relative z-10 -mt-10 sm:-mt-14">
    <div class="{{ $wrap }}">
        <div id="momentsRow" data-marquee="14" class="-mx-4 flex gap-2.5 px-4 pb-3 pt-1 sm:mx-0 sm:gap-3 sm:px-0" aria-label="Moments from our programmes">
            @foreach($media['moments'] as $p)
                <a href="#impact" class="block w-[42vw] max-w-[220px] shrink-0 rounded-xl bg-white p-1 shadow-[0_14px_30px_-14px_rgba(5,43,76,0.6)] sm:w-[250px] sm:max-w-none lg:w-[280px]">
                    @include('central.home.photo', ['p' => $p, 'ratio' => '4/3', 'class' => 'rounded-lg'])
                </a>
            @endforeach
        </div>
        <div class="-mt-1 flex justify-end">@include('central.home.marquee-controls', ['id' => 'momentsRow', 'compact' => true])</div>
    </div>
</div>
@endif
