@php $listingResults ??= collect(); @endphp
<section id="results" class="scroll-mt-24 border-b border-hair bg-cloud py-10 sm:py-12" aria-live="polite">
    <div class="{{ $wrap }}">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-leaf">{{ $browsing ? 'Directory' : 'Search results' }}</p>
                <h2 class="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $resultsTitle }}</h2>
                <p class="mt-1 text-sm text-slate2">{{ count($results['brands']) }} {{ Str::plural('brand', count($results['brands'])) }} · {{ count($results['entrepreneurs']) }} {{ Str::plural('entrepreneur', count($results['entrepreneurs'])) }}@if($listingResults->isNotEmpty()) · {{ $listingResults->count() }} {{ Str::plural('public listing', $listingResults->count()) }}@endif</p>
            </div>
            <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-hair bg-white px-3 py-2 text-sm font-semibold hover:border-leaf"><x-plat.icon name="x" class="w-4 h-4" /> {{ $browsing ? 'Close' : 'Clear search' }}</a>
        </div>

        @if(empty($results['brands']) && empty($results['entrepreneurs']) && $listingResults->isEmpty())
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <p class="font-bold">No brands or entrepreneurs match “{{ $q }}” yet.</p>
                <p class="mt-1 text-sm text-slate2">Try a category (Fashion, Food, Tech) or a district (Dhaka, Rajshahi). Is it your brand? <a href="#join" class="font-bold text-leaf hover:underline">List it for free →</a></p>
            </div>
        @else
            @if(! empty($results['brands']))
                <div class="brand-grid mt-6">
                    @foreach($results['brands'] as $brand)
                        <x-plat.brand-card :brand="$brand" />
                    @endforeach
                </div>
            @endif
            @if(! empty($results['entrepreneurs']))
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($results['entrepreneurs'] as $person)
                        <x-plat.person-card :person="$person" />
                    @endforeach
                </div>
            @endif
            @if($listingResults->isNotEmpty())
                {{-- Discovery listings (BrandDirectoryListing): researched from official sources, not registered — kept visibly separate. --}}
                <div class="mt-8">
                    <h3 class="text-lg font-extrabold tracking-tight">Well-known brands in Bangladesh</h3>
                    <p class="mt-1 max-w-3xl text-[13.5px] text-slate2">Public directory listings compiled by MetaSoft BD from each brand’s official website. These businesses have not joined, endorsed or partnered with MetaSoft BD and are not verified by us.</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4">
                        @foreach($listingResults as $listing)
                            <x-plat.listing-card :listing="$listing" />
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</section>
