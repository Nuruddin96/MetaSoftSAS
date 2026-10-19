{{-- Concept C "Consultancy & entrepreneur networking": two editorial pairs — a real photo with an inset detail shot and a text card overlapping its foot. --}}
@php
    $pairs = $media['consultancy'];
    $items = [
        ['Consultancy', 'Business consultancy', 'Sessions with entrepreneurs, one to one or in small groups, on the questions they bring to us.', $pairs[0] ?? [null, null]],
        ['Networking', 'Entrepreneur networking', 'Meetings where business owners get to know each other and build working relationships.', $pairs[1] ?? [null, null]],
    ];
@endphp
<section id="consultancy" class="scroll-mt-20 bg-cloud py-9 sm:py-14" aria-labelledby="consultancyTitle">
    <div class="{{ $wrap }}">
        <p class="{{ $eyebrow }}"><span class="{{ $rule }}"></span> Beyond the profile</p>
        <div class="fit-line"><h2 id="consultancyTitle" class="fit-line-t mt-1.5 font-extrabold leading-tight tracking-tight text-night min-[340px]:whitespace-nowrap" style="--k: 19.2; --min: 16px; --max: 28px">Consultancy & entrepreneur networking</h2></div>
        <div class="mt-5 grid gap-8 md:grid-cols-2 md:gap-6 lg:gap-8">
            @foreach($items as [$kicker, $title, $text, [$main, $side]])
                <article class="min-w-0">
                    <div class="relative">
                        @include('central.home.photo', ['p' => $main, 'ratio' => '16/10', 'class' => 'rounded-2xl', 'label' => $title, 'icon' => 'users'])
                        @if($side)
                            <div class="absolute right-3 top-3 w-[28%] rounded-xl bg-white p-1 shadow-xl">@include('central.home.photo', ['p' => $side, 'ratio' => '1/1', 'class' => 'rounded-lg'])</div>
                        @endif
                    </div>
                    <div class="relative z-10 mx-3 -mt-10 rounded-2xl bg-white p-4 shadow-[0_20px_44px_-24px_rgba(11,37,69,0.45)] sm:mx-5 sm:p-5">
                        <p class="text-[11px] font-extrabold uppercase tracking-[0.14em] text-golddk">{{ $kicker }}</p>
                        <h3 class="mt-1 text-[18px] font-extrabold leading-tight sm:text-[20px]">{{ $title }}</h3>
                        <p class="mt-1.5 text-[14px] leading-relaxed text-slate2">{{ $text }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
