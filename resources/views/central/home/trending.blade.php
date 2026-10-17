<section id="trending" class="scroll-mt-20 border-t border-hair bg-white py-12 sm:py-16">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="Showcase brands" bn="ব্র্যান্ড" sample title="Brands to Watch." sub="Sample brands shown for preview. Not a ranking." link="All brands" href="{{ url('/') }}?browse=brands#results" />

        @if(count($trending) > 1)
        <div class="mt-6 inline-flex max-w-full gap-1 overflow-x-auto rounded-2xl bg-cloud p-1 [scrollbar-width:none]" role="tablist" aria-label="Ranking type" data-tabs="trending">
            @foreach($trending as $key => $tab)
                <button type="button" role="tab" id="ttab-{{ $key }}" aria-controls="tpanel-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" data-tab="{{ $key }}"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf aria-selected:bg-white aria-selected:text-night aria-selected:shadow-sm aria-[selected=false]:text-slate2 aria-[selected=false]:hover:text-night">
                    <x-plat.icon :name="$tab['icon']" class="w-4 h-4" /> {{ $tab['label'] }}
                </button>
            @endforeach
        </div>
        @endif

        @foreach($trending as $key => $tab)
            <div id="tpanel-{{ $key }}" @if(count($trending) > 1) role="tabpanel" aria-labelledby="ttab-{{ $key }}" @endif data-panel="trending:{{ $key }}" @class(['mt-6 grid gap-4 lg:grid-cols-2', 'hidden' => ! $loop->first])>
                @foreach(array_chunk($tab['rows'], 3) as $c => $col)
                    <ul class="overflow-hidden rounded-[20px] border border-hair">
                        @foreach($col as $i => $row)
                            @php $rank = $c * 3 + $i + 1; $b = $row['brand']; @endphp
                            <li class="flex items-center gap-3 border-hair bg-white px-4 py-3.5 sm:gap-4 sm:px-5 [&:not(:last-child)]:border-b">
                                @if($row['metric'])<span class="w-7 shrink-0 text-lg font-extrabold tabular-nums {{ $rank <= 3 ? 'text-leaf' : 'text-slate-300' }}">{{ str_pad($rank, 2, '0', STR_PAD_LEFT) }}</span>@endif
                                <x-plat.logo :initials="$b['initials']" :from="$b['from']" :to="$b['to']" :size="44" :src="$b['logo'] ?? null" :alt="$b['name'].' logo'" />
                                <a href="{{ $b['url'] }}" class="min-w-0 flex-1 rounded text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                                    <span class="flex items-center gap-1.5 text-[15px] font-bold"><span class="truncate">{{ $b['name'] }}</span>@if($b['verified'] ?? false)<x-plat.verified size="w-3.5 h-3.5" />@endif</span>
                                    <span class="block truncate text-xs text-slate2">{{ $b['category'] }} · {{ $b['district'] }}</span>
                                </a>
                                @if($row['spark'])<svg class="hidden h-6 w-[72px] shrink-0 sm:block" viewBox="0 0 72 24" fill="none" aria-hidden="true"><path d="{{ $row['spark'] }}" stroke="{{ $row['up'] ? '#1769AA' : '#94A3B8' }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>@endif
                                @if($row['metric'])<span class="shrink-0 text-right text-[13px] sm:w-[92px] sm:text-[13.5px] font-bold {{ $row['up'] ? 'text-leaf' : 'text-night' }}">{{ $row['metric'] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            </div>
        @endforeach
    </div>
</section>
