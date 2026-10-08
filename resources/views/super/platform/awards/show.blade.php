@extends('layouts.super')

@section('title', $award->title.' — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $box = 'rounded-xl border border-ink/5 bg-white p-4 sm:p-5';
    $field = 'rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $statusCls = ['submitted' => 'bg-amber/15 text-amber-700', 'accepted' => 'bg-sky-50 text-sky-700', 'shortlisted' => 'bg-indigo-50 text-indigo-700', 'finalist' => 'bg-leaf/10 text-leafdk', 'rejected' => 'bg-red-50 text-red-600', 'withdrawn' => 'bg-ink/5 text-mute'];
    $recByCat = $award->recognitions->groupBy(fn ($r) => ($r->award_category_id ?? 0).':'.$r->type);
    $resultTypes = ['peoples_choice' => 'People’s Choice', 'jury_choice' => 'Jury Choice'];
    $slots = $award->categories->count() * 2;
    $filled = $award->categories->sum(fn ($c) => collect(array_keys($resultTypes))->filter(fn ($t) => $recByCat->has($c->id.':'.$t))->count());
    $steps = [
        ['Categories', $award->categories->count()],
        ['Nominees', ($statusCounts['accepted'] ?? 0) + ($statusCounts['shortlisted'] ?? 0) + ($statusCounts['finalist'] ?? 0)],
        ['Shortlisted', ($statusCounts['shortlisted'] ?? 0) + ($statusCounts['finalist'] ?? 0)],
        ['Finalists', $statusCounts['finalist'] ?? 0],
        ['Voting', $award->campaigns->count()],
        ['Results', $filled.'/'.$slots],
    ];
@endphp
<a href="{{ route('super.awards.index') }}" class="text-sm text-mute hover:text-ink">← All awards</a>
<div class="mb-4 mt-2 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="font-disp text-2xl font-bold">{{ $award->title }}</h1>
        <p class="text-sm text-mute">{{ $award->year }} · {{ $award->statusLabel() }}@if($award->status !== 'draft') · <a href="{{ route('awards.show', $award->slug) }}" target="_blank" class="text-leaf hover:underline">public page</a>@else · draft (hidden from the public until you change its status)@endif</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('super.awards.edit', $award) }}" class="rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm font-semibold">✏️ Edit / status</a>
        <a href="{{ route('super.campaigns.create', ['award' => $award->id]) }}" class="rounded-lg bg-ink px-3 py-2 text-sm font-semibold text-white">🗳 New voting campaign</a>
    </div>
</div>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<ol class="mb-5 flex flex-wrap gap-2 text-sm" aria-label="Award flow">
    @foreach($steps as $i => [$label, $count])
        <li class="flex items-center gap-2 rounded-lg border border-ink/5 bg-white px-3 py-2"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-ink text-xs font-bold text-white">{{ $i + 1 }}</span><b>{{ $label }}</b><span class="text-mute">{{ $count }}</span></li>
        @if(! $loop->last)<li class="self-center text-mute" aria-hidden="true">→</li>@endif
    @endforeach
</ol>

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">
    <div class="space-y-5">
        {{-- Step 2: pick approved brands as nominees --}}
        <section class="{{ $box }}">
            <h2 class="font-bold">Add nominees</h2>
            <p class="mb-3 text-xs text-mute">Every approved brand is listed here. Approval or verification never nominates a brand by itself — tick the brands to nominate in one category. They start as <b>Nominee</b>.</p>
            @if($award->categories->isEmpty())
                <p class="text-sm text-mute">Add categories first (right).</p>
            @elseif($approvedBrands->isEmpty())
                <p class="text-sm text-mute">No approved brands yet. Approve brands under <a href="{{ route('super.brands.index', ['status' => 'pending']) }}" class="text-leaf hover:underline">Brands</a> first.</p>
            @else
                <form method="POST" action="{{ route('super.awards.nominations.store', $award) }}" class="space-y-3" id="nomineePicker">
                    @csrf
                    <div class="flex flex-wrap gap-2">
                        <select name="award_category_id" required class="{{ $field }} min-w-[220px] flex-1" data-pick-category>
                            @foreach($award->categories as $cat)<option value="{{ $cat->id }}" data-only="{{ $cat->brand_category_id }}">{{ $cat->name }}</option>@endforeach
                        </select>
                        <input type="search" placeholder="Filter brands…" class="{{ $field }} w-48" data-pick-filter aria-label="Filter brands">
                    </div>
                    <div class="max-h-72 overflow-y-auto rounded-lg border border-ink/10">
                        @foreach($approvedBrands as $b)
                            <label class="flex items-center gap-3 border-b border-ink/5 px-3 py-2 text-sm last:border-0 hover:bg-paper/60" data-pick-row data-brand="{{ $b->id }}" data-cat="{{ $b->brand_category_id }}" data-name="{{ mb_strtolower($b->name.' '.$b->district) }}">
                                <input type="checkbox" name="brand_ids[]" value="{{ $b->id }}">
                                <span class="min-w-0 flex-1"><b>{{ $b->name }}</b> @if($b->is_verified)<x-plat.verified class="inline -mt-0.5" />@endif <span class="text-xs text-mute">· {{ $b->category?->name }} · {{ $b->district }}</span></span>
                                <span class="hidden text-xs text-mute" data-pick-note></span>
                            </label>
                        @endforeach
                    </div>
                    <button class="rounded-lg bg-leaf px-4 py-2 text-sm font-semibold text-white">Add selected as Nominees</button>
                </form>
                <script>
                    (() => {
                        const form = document.getElementById('nomineePicker');
                        const cat = form.querySelector('[data-pick-category]');
                        const filter = form.querySelector('[data-pick-filter]');
                        const taken = @json($nominatedPairs);
                        const sync = () => {
                            const opt = cat.selectedOptions[0];
                            const only = opt.dataset.only;
                            const q = filter.value.trim().toLowerCase();
                            form.querySelectorAll('[data-pick-row]').forEach((row) => {
                                const box = row.querySelector('input');
                                const note = row.querySelector('[data-pick-note]');
                                const already = (cat.value + ':' + row.dataset.brand) in taken;
                                const ineligible = only && only !== row.dataset.cat;
                                box.disabled = already || ineligible;
                                if (box.disabled) box.checked = false;
                                note.textContent = already ? 'already nominated' : (ineligible ? 'not eligible' : '');
                                note.classList.toggle('hidden', !box.disabled);
                                row.classList.toggle('opacity-50', box.disabled);
                                row.hidden = q !== '' && !row.dataset.name.includes(q);
                            });
                        };
                        cat.addEventListener('change', sync);
                        filter.addEventListener('input', sync);
                        sync();
                    })();
                </script>
            @endif
        </section>

        {{-- Steps 3-4: move nominees through the stages --}}
        <section class="{{ $box }}">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-bold">Nominations</h2>
                <form method="GET" class="flex gap-2">
                    <select name="status" onchange="this.form.submit()" class="{{ $field }}"><option value="">All statuses</option>@foreach(\App\Models\AwardNomination::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
                </form>
            </div>
            <p class="mb-3 text-xs text-mute">Nominee → Shortlisted → Finalist. Tick nominations and move them together, or change one at a time. Finalist is separate from People’s Choice / Jury Choice (recorded under Results).</p>
            <form id="bulkNoms" method="POST" action="{{ route('super.awards.nominations.bulk', $award) }}" class="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-paper p-2.5">
                @csrf
                <span class="text-sm font-semibold">Move ticked to</span>
                <select name="status" class="{{ $field }} py-1.5">
                    @foreach(['accepted', 'shortlisted', 'finalist', 'rejected'] as $k)<option value="{{ $k }}" @selected($k === 'shortlisted')>{{ \App\Models\AwardNomination::STATUSES[$k] }}</option>@endforeach
                </select>
                <button class="rounded-lg bg-ink px-3 py-1.5 text-sm font-semibold text-white">Apply</button>
            </form>
            @forelse($award->categories as $cat)
                @php $list = $nominations->get($cat->id, collect()); @endphp
                @if($list->isNotEmpty() || ! request('status'))
                    <h3 class="mt-4 flex items-center justify-between border-b border-ink/10 pb-1 text-sm font-bold">
                        <span>{{ $cat->name }}</span>
                        @if($list->isNotEmpty())<label class="text-xs font-normal text-mute"><input type="checkbox" onclick="this.closest('h3').nextElementSibling.querySelectorAll('[data-nom-cb]').forEach(c => c.checked = this.checked)"> all</label>@endif
                    </h3>
                    <div>
                        @forelse($list as $n)
                            <div class="flex flex-col gap-2 border-b border-ink/5 py-2 text-sm lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex min-w-0 items-start gap-2">
                                    <input type="checkbox" form="bulkNoms" name="ids[]" value="{{ $n->id }}" data-nom-cb class="mt-1" aria-label="Select {{ $n->brand?->name }}">
                                    <div class="min-w-0">
                                        <a href="{{ route('super.brands.show', $n->brand_id) }}" class="font-semibold text-leaf hover:underline">{{ $n->brand?->name }}</a>
                                        <span class="text-xs text-mute">· {{ $n->source === 'admin' ? 'by admin' : 'by owner' }} · {{ $n->created_at->format('d M') }}</span>
                                        @if($n->statement)<p class="mt-0.5 line-clamp-2 text-xs text-mute" title="{{ $n->statement }}">“{{ $n->statement }}”</p>@endif
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('super.awards.nominations.update', $n) }}" class="flex shrink-0 flex-wrap items-center gap-1.5">
                                    @csrf @method('PUT')
                                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $statusCls[$n->status] ?? '' }}">{{ $n->statusLabel() }}</span>
                                    <select name="status" class="{{ $field }} py-1.5">@foreach(\App\Models\AwardNomination::STATUSES as $k => $l)<option value="{{ $k }}" @selected($n->status === $k)>{{ $l }}</option>@endforeach</select>
                                    <input name="admin_note" value="{{ $n->admin_note }}" placeholder="Note (optional)" maxlength="500" class="{{ $field }} w-32 py-1.5">
                                    <button class="rounded-lg bg-ink px-2.5 py-1.5 text-xs font-semibold text-white">Save</button>
                                </form>
                            </div>
                        @empty
                            <p class="py-2 text-xs text-mute">No nominees yet.</p>
                        @endforelse
                    </div>
                @endif
            @empty
                <p class="text-sm text-mute">Add categories first (right).</p>
            @endforelse
        </section>

        {{-- Step 6: results — each category has People's Choice + Jury Choice --}}
        <section class="{{ $box }}">
            <h2 class="mb-1 font-bold">Results <span class="text-sm font-normal text-mute">· {{ $filled }} of {{ $slots }} awards decided</span></h2>
            <p class="mb-3 text-xs text-mute">Each category has two separate awards: People’s Choice (public votes) and Jury Choice (independent jury). Sponsorship never qualifies a brand for either.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-ink/10 text-left text-mute"><th class="py-2 pr-3">Category</th>@foreach($resultTypes as $l)<th class="py-2 pr-3">{{ $l }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach($award->categories as $cat)
                            <tr class="border-b border-ink/5 align-top">
                                <td class="py-2 pr-3 font-semibold">{{ $cat->name }}</td>
                                @foreach(array_keys($resultTypes) as $t)
                                    <td class="py-2 pr-3">
                                        @foreach($recByCat->get($cat->id.':'.$t, collect()) as $r)
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('super.brands.show', $r->brand_id) }}" class="text-leaf hover:underline">🏆 {{ $r->brand?->name }}</a>
                                                <form method="POST" action="{{ route('super.awards.recognitions.destroy', $r) }}" class="flex gap-1">@csrf @method('DELETE')
                                                    <input name="reason" required placeholder="Reason" class="{{ $field }} w-24 py-0.5 text-xs"><button class="text-xs text-red-600 hover:underline">Remove</button>
                                                </form>
                                            </div>
                                        @endforeach
                                        @unless($recByCat->has($cat->id.':'.$t))<span class="text-xs text-mute">—</span>@endunless
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @foreach($award->recognitions->whereNotIn('type', array_keys($resultTypes)) as $r)
                <p class="mt-2 text-sm">🏆 <b>{{ \App\Models\AwardRecognition::TYPES[$r->type] }}</b>@if($r->category) · {{ $r->category->name }}@endif — {{ $r->brand?->name }}</p>
            @endforeach
            <form method="POST" action="{{ route('super.awards.recognitions.store', $award) }}" class="mt-4 grid gap-2 rounded-lg bg-paper p-3 sm:grid-cols-2">
                @csrf
                <select name="award_category_id" required class="{{ $field }}">@foreach($award->categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach</select>
                <select name="type" required class="{{ $field }}">@foreach(\App\Models\AwardRecognition::TYPES as $k => $l)<option value="{{ $k }}" @selected($k === 'peoples_choice')>{{ $l }}</option>@endforeach</select>
                <select name="brand" required class="{{ $field }}"><option value="">Choose approved brand…</option>@foreach($approvedBrands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                <input name="title" maxlength="200" placeholder="Custom label (optional)" class="{{ $field }}">
                <button class="rounded-lg bg-amber px-3 py-2 text-sm font-semibold text-ink sm:col-span-2">Record result & notify owner</button>
            </form>
        </section>
    </div>

    <aside class="space-y-5">
        <section class="{{ $box }}">
            <h2 class="mb-3 font-bold">Categories <span class="text-sm font-normal text-mute">({{ $award->categories->count() }})</span></h2>
            <div class="max-h-[28rem] overflow-y-auto">
                @foreach($award->categories as $cat)
                    <details class="border-b border-ink/5 py-2 text-sm">
                        <summary class="flex cursor-pointer items-start justify-between gap-2">
                            <span><span class="font-semibold">{{ $cat->name }}</span><span class="block text-xs text-mute">{{ $cat->brandCategory ? 'Only '.$cat->brandCategory->name.' brands' : 'Open to all brands' }}</span></span>
                            <span class="text-xs text-leaf">Edit</span>
                        </summary>
                        <form method="POST" action="{{ route('super.awards.categories.update', $cat) }}" class="mt-2 space-y-2">
                            @csrf @method('PUT')
                            <input name="name" value="{{ $cat->name }}" required maxlength="150" class="{{ $field }} w-full">
                            <input name="description" value="{{ $cat->description }}" maxlength="500" placeholder="Description (optional)" class="{{ $field }} w-full">
                            <select name="brand_category_id" class="{{ $field }} w-full"><option value="">Eligible: all brands</option>@foreach($brandCategories as $bc)<option value="{{ $bc->id }}" @selected($cat->brand_category_id === $bc->id)>Eligible: {{ $bc->name }} only</option>@endforeach</select>
                            <button class="rounded-lg bg-ink px-3 py-1.5 text-xs font-semibold text-white">Save</button>
                        </form>
                        <form method="POST" action="{{ route('super.awards.categories.destroy', $cat) }}" class="mt-1">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">Remove category</button></form>
                    </details>
                @endforeach
            </div>
            <form method="POST" action="{{ route('super.awards.categories.store', $award) }}" class="mt-3 space-y-2">
                @csrf
                <input name="name" required maxlength="150" placeholder="e.g. Best Fashion Brand" class="{{ $field }} w-full">
                <input name="description" maxlength="500" placeholder="Short description (optional)" class="{{ $field }} w-full">
                <select name="brand_category_id" class="{{ $field }} w-full"><option value="">Eligible: all brands</option>@foreach($brandCategories as $bc)<option value="{{ $bc->id }}">Eligible: {{ $bc->name }} only</option>@endforeach</select>
                <button class="w-full rounded-lg bg-leaf px-3 py-2 text-sm font-semibold text-white">+ Add category</button>
            </form>
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-1 font-bold">Voting (step 5)</h2>
            <p class="mb-2 text-xs text-mute">Send nominees to a voting campaign of this award. Their categories are created there automatically.</p>
            @forelse($award->campaigns as $c)
                <div class="border-b border-ink/5 py-2 text-sm">
                    <a href="{{ route('super.campaigns.show', $c) }}" class="font-semibold text-leaf hover:underline">{{ $c->title }}</a>
                    <p class="text-xs text-mute">{{ $c->phaseLabel() }} · {{ \App\Models\VoteCampaign::VOTE_LIMITS[$c->vote_limit] ?? $c->vote_limit }}</p>
                    <form method="POST" action="{{ route('super.campaigns.import', $c) }}" class="mt-1.5 flex flex-wrap items-center gap-2">@csrf
                        @foreach(['finalist' => 'Finalists', 'shortlisted' => 'Shortlisted', 'accepted' => 'Nominees'] as $k => $l)
                            <label class="inline-flex items-center gap-1 text-xs"><input type="checkbox" name="statuses[]" value="{{ $k }}" @checked($k === 'finalist')> {{ $l }}</label>
                        @endforeach
                        <button class="rounded-lg border border-ink/10 px-2.5 py-1 text-xs font-semibold">Send to voting</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-mute">No voting campaign yet — create one with “New voting campaign”.</p>
            @endforelse
        </section>

        <section class="{{ $box }} text-sm">
            <h2 class="mb-2 font-bold">Dates</h2>
            <p>Nominations: {{ $award->nomination_starts_at?->format('d M Y') ?? '—' }} → {{ $award->nomination_ends_at?->format('d M Y') ?? '—' }}</p>
            <p>Voting: {{ $award->voting_starts_at?->format('d M Y') ?? '—' }} → {{ $award->voting_ends_at?->format('d M Y') ?? '—' }}</p>
            @if($award->jury_info)<h3 class="mt-3 font-semibold">Jury</h3><p class="whitespace-pre-line text-mute">{{ $award->jury_info }}</p>@endif
        </section>
    </aside>
</div>
@endsection
