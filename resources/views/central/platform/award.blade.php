@extends('layouts.platform')

@section('title', $award->title.' — MetaSoft BD')
@section('meta_description', Str::limit($award->description ?: $award->title.' — celebrating Bangladeshi brands and entrepreneurs.', 155))
@section('og_title', $award->title)

@section('page')
@php
    $typeLabels = \App\Models\AwardRecognition::TYPES;
    $typeBadge = ['winner' => 'winner', 'peoples_choice' => 'people', 'jury_choice' => 'jury'];
    $byType = $award->recognitions->groupBy('type');
    $finalists = $award->nominations->where('status', 'finalist')->groupBy('award_category_id');
    $shortlisted = $award->nominations->where('status', 'shortlisted')->groupBy('award_category_id');
    $openCampaign = $award->campaigns->first(fn ($c) => $c->isOpen());
    $dates = array_filter([
        'Nominations' => [$award->nomination_starts_at, $award->nomination_ends_at],
        'Public voting' => [$award->voting_starts_at, $award->voting_ends_at],
    ], fn ($d) => $d[0] || $d[1]);
@endphp
<section class="bg-[linear-gradient(160deg,#0A1428,#0E2350_55%,#00513C)] py-10 text-white sm:py-14">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <p class="text-[12px] font-extrabold uppercase tracking-[0.16em] text-gold">{{ $award->year }} · {{ $award->statusLabel() }}</p>
        <h1 class="mt-2 text-[28px] font-extrabold leading-tight tracking-tight sm:text-[40px]">{{ $award->title }}</h1>
        @if($award->bn_title)<p class="mt-1 font-body text-lg text-emerald-200" lang="bn">{{ $award->bn_title }}</p>@endif
        @if($award->description)<p class="mt-4 max-w-2xl whitespace-pre-line text-[15px] leading-relaxed text-white/75">{{ $award->description }}</p>@endif
        <div class="mt-6 flex flex-wrap gap-2">
            @if($award->acceptsNominations())
                <a href="{{ route('owner.awards') }}" class="rounded-xl bg-gold px-5 py-3 text-sm font-bold text-night hover:brightness-105">Nominate your brand</a>
            @endif
            @if($openCampaign)
                <a href="{{ route('voting.campaign', $openCampaign->slug) }}" class="rounded-xl bg-flag px-5 py-3 text-sm font-bold text-white hover:brightness-95">Vote now</a>
            @endif
        </div>
    </div>
</section>

<section class="mx-auto grid max-w-5xl gap-6 px-4 py-10 sm:px-6 lg:grid-cols-[1fr_300px]">
    <div class="space-y-8">
        @if($award->recognitions->isNotEmpty())
            <div>
                <h2 class="mb-3 text-xl font-extrabold">Results</h2>
                <div class="space-y-5">
                    @foreach(['winner', 'jury_choice', 'peoples_choice'] as $type)
                        @if($byType->has($type))
                            <div>
                                <h3 class="mb-2 text-sm font-extrabold uppercase tracking-wide text-slate2">{{ $typeLabels[$type] }}</h3>
                                <ul class="grid gap-2 sm:grid-cols-2">
                                    @foreach($byType[$type] as $r)
                                        @php $b = $r->brand; [$f, $t] = $b->gradient(); @endphp
                                        <li class="flex items-center gap-3 rounded-2xl border border-hair bg-white p-3">
                                            <x-plat.logo :initials="$b->initials()" :from="$f" :to="$t" :size="44" :src="$b->logoUrl()" :alt="''" />
                                            <div class="min-w-0">
                                                <a href="{{ $b->profileUrl() }}" class="flex items-center gap-1.5 font-bold hover:text-leaf"><span class="truncate">{{ $b->name }}</span>@if($b->is_verified)<x-plat.verified />@endif</a>
                                                <x-plat.badge :type="$typeBadge[$type]" :label="$r->category?->name ?? $r->label()" size="xs" class="mt-1" />
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div>
            <h2 class="mb-3 text-xl font-extrabold">Categories</h2>
            @forelse($award->categories as $cat)
                <div class="mb-3 rounded-[20px] border border-hair bg-white p-4 sm:p-5">
                    <h3 class="font-extrabold">{{ $cat->name }}</h3>
                    @if($cat->description)<p class="mt-1 text-sm text-slate2">{{ $cat->description }}</p>@endif
                    @foreach(['Finalists' => $finalists->get($cat->id), 'Shortlisted' => $shortlisted->get($cat->id)] as $label => $list)
                        @if($list && $list->isNotEmpty())
                            <p class="mt-3 text-xs font-extrabold uppercase tracking-wide text-slate2">{{ $label }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($list as $n)
                                    <a href="{{ $n->brand->profileUrl() }}" class="inline-flex items-center gap-1.5 rounded-full border border-hair px-3 py-1.5 text-sm font-semibold hover:border-leaf">{{ $n->brand->name }}@if($n->brand->is_verified)<x-plat.verified class="w-3.5 h-3.5" />@endif</a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </div>
            @empty
                <p class="rounded-2xl border border-dashed border-hair bg-white px-4 py-8 text-center text-sm text-slate2">Categories will be announced soon.</p>
            @endforelse
        </div>

        @if($award->rules)
            <div class="rounded-[20px] border border-hair bg-white p-5 sm:p-7">
                <h2 class="text-xl font-extrabold">Rules</h2>
                <div class="mt-3 whitespace-pre-line text-[15px] leading-relaxed text-slate2">{{ $award->rules }}</div>
            </div>
        @endif
    </div>

    <aside class="space-y-4">
        @if($dates)
            <div class="rounded-[20px] border border-hair bg-white p-5">
                <h2 class="text-[15px] font-extrabold">Key dates</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    @foreach($dates as $label => [$start, $end])
                        <div><dt class="font-semibold text-slate2">{{ $label }}</dt><dd class="font-bold">{{ $start?->format('j M Y') ?? '—' }} – {{ $end?->format('j M Y') ?? '—' }}</dd></div>
                    @endforeach
                </dl>
            </div>
        @endif
        @if($award->jury_info)
            <div class="rounded-[20px] border border-hair bg-white p-5">
                <h2 class="text-[15px] font-extrabold">Jury</h2>
                <div class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate2">{{ $award->jury_info }}</div>
            </div>
        @endif
        <div class="rounded-[20px] bg-mint p-5 text-sm text-leafdk">
            <p class="font-extrabold">How recognition works</p>
            <p class="mt-1 leading-relaxed">Winner, Jury Choice and People’s Choice are separate results. Finalists are selected by the MetaSoft BD team. Paid sponsorship never affects any result.</p>
        </div>
    </aside>
</section>
@endsection
