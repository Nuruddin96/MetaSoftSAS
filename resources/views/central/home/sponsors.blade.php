<section id="sponsors" class="scroll-mt-20 bg-cream py-16 sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head eyebrow="Partners & sponsors" bn="পার্টনার"
            title="Backed by organisations that believe in Bangladeshi enterprise."
            link="Become a sponsor" href="{{ $wa('I am interested in sponsoring MetaSoft BD awards / events.') }}" />

        <div class="mt-10 space-y-7">
            @foreach($sponsorTiers as $tier)
                <div>
                    <div class="flex items-center gap-3">
                        <span class="text-[11px] font-extrabold uppercase tracking-[0.16em] {{ $loop->first ? 'text-golddk' : 'text-slate2' }}">{{ $tier['name'] }}</span>
                        <span class="h-px flex-1 bg-hair"></span>
                    </div>
                    <div @class(['mt-3 grid gap-3',
                        'grid-cols-1' => $tier['size'] === 'xl',
                        'grid-cols-1 sm:grid-cols-3' => $tier['size'] === 'lg',
                        'grid-cols-2 sm:grid-cols-5' => $tier['size'] === 'md'])>
                        @for($i = 0; $i < $tier['slots']; $i++)
                            <div @class(['flex items-center justify-center gap-2.5 rounded-2xl border border-hair bg-white text-slate-400',
                                'h-28' => $tier['size'] === 'xl', 'h-20' => $tier['size'] === 'lg', 'h-16' => $tier['size'] === 'md'])>
                                <span @class(['rounded-lg bg-slate-200', 'h-9 w-9' => $tier['size'] === 'xl', 'h-6 w-6' => $tier['size'] !== 'xl'])></span>
                                <span @class(['font-extrabold tracking-tight', 'text-xl' => $tier['size'] === 'xl', 'text-sm' => $tier['size'] !== 'xl'])>{{ $tier['size'] === 'xl' ? 'Title partner — slot open' : 'Partner logo' }}</span>
                            </div>
                        @endfor
                    </div>
                </div>
            @endforeach
            <div class="grid gap-6 sm:grid-cols-2">
                @foreach(['Category Sponsors', 'Strategic Partners'] as $name)
                    <div>
                        <div class="flex items-center gap-3"><span class="text-[11px] font-extrabold uppercase tracking-[0.16em] text-slate2">{{ $name }}</span><span class="h-px flex-1 bg-hair"></span></div>
                        <div class="mt-3 grid grid-cols-3 gap-3">
                            @for($i = 0; $i < 3; $i++)
                                <div class="flex h-14 items-center justify-center rounded-xl border border-hair bg-white text-xs font-bold text-slate-400">Logo</div>
                            @endfor
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="mt-8 flex items-start gap-2 rounded-2xl border border-hair bg-white p-4 text-[13px] text-slate2">
            <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0 text-leaf" />
            Sponsors fund events and platform growth. They never influence nominations, public votes or jury decisions — a sponsored category is still judged on the same published rules.
        </p>
    </div>
</section>
