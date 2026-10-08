@extends('layouts.super')

@section('title', 'Notifications — Brand platform')

@section('content')
@include('super.platform._tabs')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-disp text-2xl font-bold">🔔 Platform notifications</h1>
    @if($notifications->contains(fn ($n) => ! $n->read_at))
        <form method="POST" action="{{ route('super.platform-notifications.read') }}">@csrf<button class="rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm font-semibold">Mark all as read</button></form>
    @endif
</div>

<div class="divide-y divide-ink/5 overflow-hidden rounded-xl border border-ink/5 bg-white">
    @forelse($notifications as $n)
        <div class="flex items-start gap-3 p-4 {{ $n->read_at ? '' : 'bg-amber/5' }}">
            @unless($n->read_at)<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-red-500"></span>@endunless
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ $n->title }}</p>
                @if($n->body)<p class="text-sm text-mute">{{ $n->body }}</p>@endif
                <p class="text-xs text-mute">{{ $n->created_at->format('d M Y, h:i A') }} · {{ str_replace('_', ' ', $n->type) }}</p>
            </div>
            @if($n->url)<a href="{{ $n->url }}" class="shrink-0 text-sm text-leaf hover:underline">Open →</a>@endif
        </div>
    @empty
        <p class="p-6 text-center text-mute">No notifications yet.</p>
    @endforelse
</div>
<div class="mt-4">{{ $notifications->links() }}</div>
@endsection
