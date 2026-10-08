@php
    $tones = [
        'navy' => 'bg-[#E8EDF8] text-navy', 'rose' => 'bg-rose-50 text-rose-700', 'indigo' => 'bg-indigo-50 text-indigo-700',
        'sky' => 'bg-sky-50 text-sky-700', 'cyan' => 'bg-cyan-50 text-cyan-700', 'paid' => 'bg-cloud text-slate2 border border-dashed border-slate-300',
    ];
@endphp
<section id="recognition" class="scroll-mt-20 bg-[linear-gradient(140deg,#0A1428,#112A55)] py-16 text-white sm:py-20 lg:py-24">
    <div class="{{ $wrap }}">
        <x-plat.section-head dark eyebrow="Awards & recognition" bn="স্বীকৃতি"
            title="Six ways to be recognised — none of them for sale."
            sub="Each recognition has its own published criteria, decision-makers and audit trail." />

        <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($recognition as $r)
                <article class="flex gap-4 rounded-2xl border border-white/10 bg-white/[0.04] p-5 transition hover:bg-white/[0.07] sm:p-6">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full" style="background:linear-gradient(135deg,{{ $r['color'] }},#0A1428);box-shadow:inset 0 0 0 2px {{ $r['color'] }}">
                        <x-plat.icon :name="$r['icon']" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-[17px] font-extrabold">{{ $r['name'] }}</h3>
                        <p class="font-body text-[13px] text-white/50" lang="bn">{{ $r['bn'] }}</p>
                        <p class="mt-2 text-sm leading-relaxed text-white/70">{{ $r['desc'] }}</p>
                        <span class="mt-3 inline-block rounded-full px-2.5 py-0.5 text-[11px] font-semibold text-white" style="background-color: {{ $r['color'] }}33">{{ $r['how'] }}</span>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- The credibility framework: what each label means and whether money is involved --}}
        <div class="mt-6 rounded-3xl bg-white p-5 text-night sm:p-7">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-lg font-extrabold">How every label on MetaSoft BD is earned</h3>
                <span class="text-[13px] font-semibold text-slate2">Transparency report published after every season</span>
            </div>
            <ul class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                @foreach($labels as $l)
                    <li class="rounded-2xl p-4 {{ $tones[$l['tone']] }}">
                        <span class="text-[10px] font-extrabold uppercase tracking-[0.14em]">{{ $l['kind'] }}</span>
                        <span class="mt-1 block text-[15px] font-extrabold text-night">{{ $l['name'] }}</span>
                        <span class="mt-0.5 block text-xs text-slate2">{{ $l['desc'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-[13px] text-slate2">Nomination fees, sponsorship and promoted placements are kept on a separate track: they never add votes, influence jury scores or appear as an award.</p>
        </div>
    </div>
</section>
