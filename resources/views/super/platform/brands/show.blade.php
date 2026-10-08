@extends('layouts.super')

@section('title', $brand->name.' — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $box = 'rounded-xl border border-ink/5 bg-white p-4 sm:p-5';
    $field = 'w-full rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $btn = 'rounded-lg px-3 py-2 text-sm font-semibold';
    $statusCls = ['pending' => 'bg-amber/15 text-amber-700', 'approved' => 'bg-leaf/10 text-leafdk', 'rejected' => 'bg-red-50 text-red-600', 'suspended' => 'bg-ink/10 text-ink'];
    $pending = $brand->pendingChange;
    $fieldNames = ['name' => 'Brand name', 'logo_path' => 'Logo', 'brand_category_id' => 'Category', 'district' => 'District', 'division' => 'Division', 'founder_name' => 'Founder', 'phone' => 'Phone', 'email' => 'Email'];
    $show = fn ($f, $v) => $f === 'brand_category_id' ? ($categories[$v] ?? '—') : ($v ?? '—');
@endphp

<a href="{{ route('super.brands.index') }}" class="text-sm text-mute hover:text-ink">← All brands</a>
<div class="mt-2 mb-5 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3">
        @if($brand->logoUrl())<img src="{{ $brand->logoUrl() }}" alt="" class="h-14 w-14 rounded-xl object-cover">@else<span class="flex h-14 w-14 items-center justify-center rounded-xl bg-ink/5 font-bold">{{ $brand->initials() }}</span>@endif
        <div>
            <h1 class="flex items-center gap-2 font-disp text-2xl font-bold">{{ $brand->name }} @if($brand->is_verified)<x-plat.verified size="w-5 h-5" />@endif</h1>
            <p class="text-sm text-mute">
                <span class="rounded px-2 py-0.5 text-xs font-semibold capitalize {{ $statusCls[$brand->status] ?? '' }}">{{ $brand->status }}</span>
                #{{ $brand->id }} · {{ $brand->category?->name ?? 'No category' }} · {{ $brand->district }}, {{ $brand->division }}
                @if($brand->slug) · <a href="{{ $brand->profileUrl() }}" target="_blank" class="text-leaf hover:underline">/brand/{{ $brand->slug }}</a>@endif
            </p>
        </div>
    </div>
    <a href="{{ route('super.brands.edit', $brand) }}" class="{{ $btn }} border border-ink/10 bg-white hover:border-leaf">✏️ Edit brand</a>
</div>

@if($brand->status_reason)
    <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><b>Reason shown to owner:</b> {{ $brand->status_reason }}</p>
@endif
@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>
@endif

<div class="grid gap-5 xl:grid-cols-[1fr_380px]">
    <div class="space-y-5">
        @if($pending)
            <section class="{{ $box }} border-sky-200">
                <h2 class="mb-3 font-bold">✏️ Profile change request <span class="text-xs font-normal text-mute">· {{ $pending->updated_at->diffForHumans() }}</span></h2>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-mute"><th class="py-1 pr-3">Field</th><th class="py-1 pr-3">Current</th><th class="py-1">Requested</th></tr></thead>
                    <tbody>
                        @foreach($pending->changes as $f => $v)
                            <tr class="border-t border-ink/5 align-top">
                                <td class="py-2 pr-3 font-semibold">{{ $fieldNames[$f] ?? $f }}</td>
                                @if($f === 'logo_path')
                                    <td class="py-2 pr-3">@if($brand->logoUrl())<img src="{{ $brand->logoUrl() }}" alt="" class="h-12 w-12 rounded object-cover">@else — @endif</td>
                                    <td class="py-2"><img src="{{ asset('storage/'.$v) }}" alt="" class="h-12 w-12 rounded object-cover"></td>
                                @else
                                    <td class="py-2 pr-3 text-mute">{{ $show($f, $brand->{$f}) }}</td>
                                    <td class="py-2 font-semibold text-leafdk">{{ $show($f, $v) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3 flex flex-wrap items-start gap-2">
                    <form method="POST" action="{{ route('super.brands.changes.approve', $pending) }}">@csrf<button class="{{ $btn }} bg-leaf text-white">Approve changes</button></form>
                    <form method="POST" action="{{ route('super.brands.changes.reject', $pending) }}" class="flex flex-1 gap-2">@csrf
                        <input name="note" required maxlength="500" placeholder="Reason (shown to owner)" class="{{ $field }}">
                        <button class="{{ $btn }} shrink-0 bg-red-600 text-white">Reject</button>
                    </form>
                </div>
            </section>
        @endif

        <section class="{{ $box }}">
            <h2 class="mb-3 font-bold">Details</h2>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                @foreach([
                    'Founder / owner' => $brand->founder_name, 'Brand phone' => $brand->phone, 'Brand email' => $brand->email,
                    'Sub-category' => $brand->sub_category, 'Founded' => $brand->founded_year, 'Website' => $brand->website,
                    'Facebook' => $brand->facebook, 'Instagram' => $brand->instagram, 'TikTok' => $brand->tiktok, 'YouTube' => $brand->youtube,
                    'Submitted' => $brand->created_at?->format('d M Y, h:i A'), 'Approved' => $brand->approved_at?->format('d M Y, h:i A'),
                    'Verified at' => $brand->verified_at?->format('d M Y'), 'Profile views' => number_format($brand->views_count),
                ] as $k => $v)
                    <div><dt class="text-xs text-mute">{{ $k }}</dt><dd class="break-words">@if($v && str_starts_with((string) $v, 'http'))<a href="{{ $v }}" target="_blank" rel="noopener nofollow" class="text-leaf hover:underline">{{ $v }}</a>@else{{ $v ?: '—' }}@endif</dd></div>
                @endforeach
            </dl>
            @if($brand->description)<h3 class="mt-4 text-xs text-mute">Description</h3><p class="whitespace-pre-line text-sm">{{ $brand->description }}</p>@endif
            @if($brand->products_info)<h3 class="mt-4 text-xs text-mute">Products / services</h3><p class="whitespace-pre-line text-sm">{{ $brand->products_info }}</p>@endif
            @if($brand->gallery)
                <div class="mt-4 flex flex-wrap gap-2">@foreach($brand->galleryUrls() as $src)<a href="{{ $src }}" target="_blank"><img src="{{ $src }}" alt="" class="h-20 w-20 rounded-lg object-cover"></a>@endforeach</div>
            @endif
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-3 font-bold">Awards & voting</h2>
            @foreach($brand->recognitions as $r)
                <p class="text-sm">🏆 <b>{{ \App\Models\AwardRecognition::TYPES[$r->type] }}</b> — {{ $r->award?->title }}</p>
            @endforeach
            @foreach($brand->nominations as $n)
                <p class="text-sm">🎖 {{ $n->award?->title }} · {{ $n->category?->name }} — <b>{{ $n->statusLabel() }}</b></p>
            @endforeach
            @foreach($brand->voteEntries as $e)
                <p class="text-sm">🗳 <a href="{{ route('super.campaigns.show', $e->campaign_id) }}" class="text-leaf hover:underline">{{ $e->campaign?->title }}</a> · {{ $e->category?->name }} — {{ number_format($e->votes_count) }} votes @unless($e->is_active)<span class="text-red-600">(inactive)</span>@endunless</p>
            @endforeach
            @if($brand->recognitions->isEmpty() && $brand->nominations->isEmpty() && $brand->voteEntries->isEmpty())<p class="text-sm text-mute">None yet.</p>@endif
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-3 font-bold">Audit trail</h2>
            <ul class="space-y-2 text-sm">
                @forelse($audit as $log)
                    <li class="border-b border-ink/5 pb-2">
                        <span class="font-mono text-xs">{{ $log->action }}</span> by <b>{{ $log->actor_name }}</b> <span class="text-xs text-mute">({{ $log->actor_type }}) · {{ $log->created_at?->format('d M Y, h:i A') }}</span>
                        @if($log->reason)<p class="text-xs text-mute">Reason: {{ $log->reason }}</p>@endif
                    </li>
                @empty
                    <li class="text-mute">No activity yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <aside class="space-y-5">
        <section class="{{ $box }}">
            <h2 class="mb-3 font-bold">Listing status</h2>
            <div class="space-y-2">
                @if(in_array($brand->status, ['pending', 'rejected'], true))
                    <form method="POST" action="{{ route('super.brands.status', $brand) }}">@csrf<input type="hidden" name="action" value="approve">
                        <button class="{{ $btn }} w-full bg-leaf text-white hover:bg-leafdk">✅ Approve & publish</button></form>
                @endif
                @if($brand->status === 'suspended')
                    <form method="POST" action="{{ route('super.brands.status', $brand) }}">@csrf<input type="hidden" name="action" value="restore">
                        <button class="{{ $btn }} w-full bg-leaf text-white">♻️ Restore</button></form>
                @endif
                @if(in_array($brand->status, ['pending', 'approved'], true))
                    <form method="POST" action="{{ route('super.brands.status', $brand) }}" class="space-y-2 border-t border-ink/5 pt-2">@csrf
                        <input type="hidden" name="action" value="{{ $brand->status === 'pending' ? 'reject' : 'suspend' }}">
                        <input name="reason" required maxlength="500" placeholder="Reason (shown to owner)" class="{{ $field }}">
                        <button class="{{ $btn }} w-full bg-red-600 text-white">{{ $brand->status === 'pending' ? '✖ Reject' : '⏸ Suspend' }}</button>
                    </form>
                @endif
            </div>
        </section>

        <section class="{{ $box }}">
            <h2 class="font-bold">Verification <x-plat.verified class="inline -mt-0.5" /></h2>
            <p class="mb-3 text-xs text-mute">Identity/contact checked by MetaSoft BD. Independent of featured, sponsored and awards.</p>
            <form method="POST" action="{{ route('super.brands.verification', $brand) }}" class="space-y-2">@csrf
                <input type="hidden" name="verified" value="{{ $brand->is_verified ? 0 : 1 }}">
                @if($brand->is_verified)<input name="reason" maxlength="500" placeholder="Reason for removal (optional)" class="{{ $field }}">@endif
                <button class="{{ $btn }} w-full {{ $brand->is_verified ? 'border border-ink/10' : 'bg-sky-600 text-white' }}" @disabled(! $brand->is_verified && $brand->status !== 'approved')>{{ $brand->is_verified ? 'Remove verification' : 'Mark as verified' }}</button>
            </form>
        </section>

        <section class="{{ $box }}">
            <h2 class="font-bold">⭐ Featured (editorial)</h2>
            <p class="mb-3 text-xs text-mute">Editor’s pick — never sold.</p>
            <form method="POST" action="{{ route('super.brands.featured', $brand) }}" class="flex gap-2">@csrf
                <input type="hidden" name="featured" value="{{ $brand->is_featured ? 0 : 1 }}">
                @unless($brand->is_featured)<input type="number" name="featured_order" min="0" max="9999" value="{{ $brand->featured_order }}" class="{{ $field }} w-24" title="Order (lower first)">@endunless
                <button class="{{ $btn }} flex-1 {{ $brand->is_featured ? 'border border-ink/10' : 'bg-ink text-white' }}" @disabled(! $brand->is_featured && $brand->status !== 'approved')>{{ $brand->is_featured ? 'Unfeature' : 'Feature' }}</button>
            </form>
        </section>

        <section class="{{ $box }}">
            <h2 class="font-bold">💳 Sponsored placement (paid)</h2>
            <p class="mb-3 text-xs text-mute">Always labelled “Sponsored”. Never grants verification or any award.</p>
            <form method="POST" action="{{ route('super.brands.sponsorship', $brand) }}" class="flex gap-2">@csrf
                <input type="hidden" name="sponsored" value="{{ $brand->is_sponsored ? 0 : 1 }}">
                @unless($brand->is_sponsored)<input type="date" name="sponsored_until" min="{{ now()->toDateString() }}" class="{{ $field }}" title="Until (optional)">@endunless
                <button class="{{ $btn }} shrink-0 {{ $brand->is_sponsored ? 'border border-ink/10' : 'bg-amber text-ink' }}">{{ $brand->is_sponsored ? 'End sponsorship' : 'Start' }}</button>
            </form>
            @if($brand->is_sponsored)<p class="mt-2 text-xs text-mute">Running {{ $brand->sponsored_until ? 'until '.$brand->sponsored_until->format('d M Y') : 'with no end date' }}.</p>@endif
        </section>

        <section class="{{ $box }}">
            <h2 class="mb-2 font-bold">👤 Owner login</h2>
            @if($brand->owner)
                <p class="text-sm"><b>{{ $brand->owner->name }}</b></p>
                <p class="text-xs text-mute">{{ $brand->owner->email }} · {{ $brand->owner->phone }}</p>
                <p class="text-xs text-mute">Last login: {{ $brand->owner->last_login_at?->diffForHumans() ?? 'never' }} · <span class="capitalize">{{ $brand->owner->status }}</span></p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('super.brands.owner-password', $brand) }}" onsubmit="return confirm('Generate a new password for this owner?')">@csrf<button class="{{ $btn }} border border-ink/10">Reset password</button></form>
                    <form method="POST" action="{{ route('super.brands.owner-status', $brand) }}">@csrf
                        <input type="hidden" name="status" value="{{ $brand->owner->status === 'active' ? 'suspended' : 'active' }}">
                        <button class="{{ $btn }} border border-ink/10">{{ $brand->owner->status === 'active' ? 'Block login' : 'Unblock login' }}</button>
                    </form>
                </div>
            @else
                <p class="text-sm text-mute">Added by admin — no owner account.</p>
            @endif
        </section>

        <section class="{{ $box }} border-red-200">
            <h2 class="mb-2 font-bold text-red-700">Delete brand</h2>
            <p class="mb-2 text-xs text-mute">Removes the public profile and retires its link. Vote history is kept for audit.</p>
            <form method="POST" action="{{ route('super.brands.destroy', $brand) }}" class="space-y-2" onsubmit="return confirm('Delete {{ addslashes($brand->name) }}?')">@csrf @method('DELETE')
                <input name="reason" required maxlength="500" placeholder="Reason" class="{{ $field }}">
                <button class="{{ $btn }} w-full bg-red-600 text-white">Delete</button>
            </form>
        </section>
    </aside>
</div>
@endsection
