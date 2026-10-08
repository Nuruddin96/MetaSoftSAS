@extends('layouts.brand-owner')

@section('title', 'Notifications — MetaSoft BD')

@section('page')
@php
    $icons = [
        'brand_approved' => ['check', 'bg-mint text-leafdk'], 'brand_rejected' => ['info', 'bg-rose-50 text-rose-600'], 'brand_suspended' => ['info', 'bg-rose-50 text-rose-600'],
        'verification_changed' => ['shield', 'bg-sky-50 text-sky-600'], 'change_approved' => ['check', 'bg-mint text-leafdk'], 'change_rejected' => ['info', 'bg-rose-50 text-rose-600'],
        'voting_started' => ['vote', 'bg-rose-50 text-flag'], 'voting_ended' => ['clock', 'bg-cloud text-slate2'], 'voting_entry' => ['vote', 'bg-rose-50 text-flag'],
        'finalist' => ['award', 'bg-mint text-leafdk'], 'winner' => ['trophy', 'bg-gold/25 text-golddk'],
    ];
@endphp
<div class="flex flex-wrap items-end justify-between gap-3">
    <h1 class="text-[24px] font-extrabold tracking-tight sm:text-[28px]">Notifications</h1>
    @if($notifications->contains(fn ($n) => ! $n->read_at))
        <form method="POST" action="{{ route('owner.notifications.read') }}">@csrf
            <button class="rounded-xl border border-hair bg-white px-4 py-2 text-sm font-bold hover:border-leaf">Mark all as read</button>
        </form>
    @endif
</div>

<div class="mt-5 divide-y divide-hair overflow-hidden rounded-[20px] border border-hair bg-white">
    @forelse($notifications as $n)
        @php [$icon, $cls] = $icons[$n->type] ?? ['megaphone', 'bg-cloud text-slate2']; @endphp
        <div class="flex gap-3 p-4 {{ $n->read_at ? '' : 'bg-mint/40' }}">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $cls }}"><x-plat.icon :name="$icon" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
                <p class="font-bold">{{ $n->title }}</p>
                @if($n->body)<p class="mt-0.5 break-words text-sm text-slate2">{{ $n->body }}</p>@endif
                <p class="mt-1 text-xs text-slate2">{{ $n->created_at->format('j M Y, g:i A') }}@if($n->url) · <a href="{{ $n->url }}" class="font-semibold text-leaf hover:underline">Open</a>@endif</p>
            </div>
            @unless($n->read_at)<span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-flag" aria-label="Unread"></span>@endunless
        </div>
    @empty
        <p class="p-8 text-center text-sm text-slate2">No notifications yet.</p>
    @endforelse
</div>
<div class="mt-4">{{ $notifications->links() }}</div>
@endsection
