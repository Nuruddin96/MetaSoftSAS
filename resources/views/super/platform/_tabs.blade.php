{{-- Recognition platform section tabs (Super Admin). --}}
@php
    $platTabs = [
        ['super.brands.index', 'Brands', 'super.brands.index|super.brands.show|super.brands.edit|super.brands.create', \App\Models\Brand::where('status', 'pending')->count()],
        ['super.brands.changes', 'Change requests', 'super.brands.changes', \App\Models\BrandChangeRequest::where('status', 'pending')->whereHas('brand')->count()],
        ['super.brand-categories.index', 'Categories', 'super.brand-categories.*', 0],
        ['super.awards.index', 'Awards', 'super.awards.*', \App\Models\AwardNomination::where('status', 'submitted')->count()],
        ['super.campaigns.index', 'Voting', 'super.campaigns.*', 0],
        ['super.homepage.edit', 'Homepage', 'super.homepage.*', 0],
        ['super.platform-notifications', 'Notifications', 'super.platform-notifications', \App\Models\PlatformNotification::forAdmins()->unread()->count()],
        ['super.platform-audit', 'Audit log', 'super.platform-audit', 0],
    ];
@endphp
<nav class="mb-6 -mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0" aria-label="Brand platform">
    <div class="flex min-w-max gap-1 rounded-xl bg-white p-1 border border-ink/5">
        @foreach($platTabs as [$r, $label, $match, $count])
            @php $on = collect(explode('|', $match))->contains(fn ($m) => request()->routeIs($m)); @endphp
            <a href="{{ route($r) }}" class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold {{ $on ? 'bg-ink text-white' : 'text-mute hover:bg-paper hover:text-ink' }}">
                {{ $label }} @if($count)<span class="rounded-full {{ $on ? 'bg-amber text-ink' : 'bg-red-500 text-white' }} px-1.5 text-[11px] font-bold">{{ $count }}</span>@endif
            </a>
        @endforeach
    </div>
</nav>
