@php
    $tones = [
        'navy' => 'bg-[#E8EDF8] text-navy', 'rose' => 'bg-mint text-leaf', 'indigo' => 'bg-[#E8EDF8] text-leafdk',
        'sky' => 'bg-sky-50 text-sky-700', 'cyan' => 'bg-cloud text-leaf border border-hair', 'paid' => 'bg-cloud text-slate2 border border-dashed border-slate-300',
    ];
@endphp
<section id="recognition" class="scroll-mt-20 bg-[linear-gradient(140deg,#052B4C,#0B4A80)] py-12 text-white sm:py-16 lg:py-20">
    <div class="{{ $wrap }}">
        <x-plat.section-head dark eyebrow="Awards & recognition" bn="স্বীকৃতি"
            title="Six Ways to Be Recognised."
            sub="People’s Choice and Jury Choice are awarded in every category." />

        {{-- Two per row on phones and desktop alike; a single column only below 360px, where two would be unreadable. --}}
        <div class="mt-8 grid gap-3 min-[360px]:grid-cols-2 sm:gap-4">
            @foreach($recognition as $r)
                <article class="flex min-w-0 flex-col gap-2.5 rounded-2xl border border-white/10 bg-white/[0.04] p-3.5 transition hover:bg-white/[0.07] sm:flex-row sm:gap-4 sm:p-5">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full sm:h-11 sm:w-11" style="background:linear-gradient(135deg,{{ $r['color'] }},#052B4C);box-shadow:inset 0 0 0 2px {{ $r['color'] }}">
                        <x-plat.icon :name="$r['icon']" class="w-4 h-4 sm:w-5 sm:h-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-[14px] font-extrabold leading-snug sm:text-[16px]">{{ $r['name'] }}</h3>
                        <p class="font-body text-[12px] text-white/50 sm:text-[13px]" lang="bn">{{ $r['bn'] }}</p>
                        <p class="mt-1.5 text-[12.5px] leading-snug text-white/70 sm:text-sm">{{ $r['desc'] }}</p>
                        <span class="mt-2 inline-block rounded-full px-2 py-0.5 text-[10.5px] font-semibold text-white sm:text-[11px]" style="background-color: {{ $r['color'] }}33">{{ $r['how'] }}</span>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- Disclosure: what each label means and whether money is involved --}}
        <div class="mt-5 rounded-3xl bg-white p-4 text-night sm:p-6">
            <h3 class="text-base font-extrabold sm:text-lg">How each label is earned</h3>
            <ul class="mt-4 grid grid-cols-2 gap-2.5 md:grid-cols-3 lg:grid-cols-6">
                @foreach($labels as $l)
                    <li class="rounded-2xl p-3 sm:p-4 {{ $tones[$l['tone']] }}">
                        <span class="text-[10px] font-extrabold uppercase tracking-[0.14em]">{{ $l['kind'] }}</span>
                        <span class="mt-1 block text-[14px] font-extrabold text-night sm:text-[15px]">{{ $l['name'] }}</span>
                        <span class="mt-0.5 block text-xs text-slate2">{{ $l['desc'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-[13px] text-slate2">Sponsorships and promoted placements never add votes, affect jury scores or count as awards.</p>
        </div>
    </div>
</section>
