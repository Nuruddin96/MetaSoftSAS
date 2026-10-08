<section id="results" class="scroll-mt-24 border-b border-hair bg-cloud py-10 sm:py-12" aria-live="polite">
    <div class="{{ $wrap }}">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-leaf">{{ $browsing ? 'Directory' : 'Search results' }}</p>
                <h2 class="mt-2 text-2xl font-extrabold tracking-tight sm:text-3xl">{{ $resultsTitle }}</h2>
                <p class="mt-1 text-sm text-slate2">{{ count($results['brands']) }} {{ Str::plural('brand', count($results['brands'])) }} · {{ count($results['entrepreneurs']) }} {{ Str::plural('entrepreneur', count($results['entrepreneurs'])) }}</p>
            </div>
            <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-hair bg-white px-3 py-2 text-sm font-semibold hover:border-leaf"><x-plat.icon name="x" class="w-4 h-4" /> {{ $browsing ? 'Close' : 'Clear search' }}</a>
        </div>

        @if(empty($results['brands']) && empty($results['entrepreneurs']))
            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <p class="font-bold">No brands or entrepreneurs match “{{ $q }}” yet.</p>
                <p class="mt-1 text-sm text-slate2">Try a category (Fashion, Food, Tech) or a district (Dhaka, Rajshahi). Is it your brand? <a href="#join" class="font-bold text-leaf hover:underline">List it for free →</a></p>
            </div>
        @else
            @if(! empty($results['brands']))
                <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
        @endif
    </div>
</section>
