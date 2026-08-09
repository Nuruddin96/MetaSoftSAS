@extends('layouts.panel')

@section('title', 'Facebook Page বাছাই করুন')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">Facebook Page বাছাই করুন</h1>
<p class="text-sm text-mute mb-6">আপনার Facebook অ্যাকাউন্টের যে Page-টি এই স্টোরের সাথে যুক্ত করতে চান, সেটি বেছে নিন।</p>

<div class="max-w-2xl space-y-3">
    @forelse ($pages as $page)
        <x-ui.card padding="sm" class="flex items-center justify-between gap-4">
            <div>
                <p class="font-semibold">{{ $page['name'] ?? 'নাম নেই' }}</p>
                <p class="text-xs text-mute">Page ID: {{ $page['id'] }}@if (! empty($page['category'])) &middot; {{ $page['category'] }}@endif</p>
            </div>

            @if (in_array($page['id'], $connectedIds))
                <x-ui.badge tone="leaf">✅ কানেক্টেড</x-ui.badge>
            @else
                <form method="POST" action="{{ route('tenant.facebook.pages.connect', $page['id']) }}">
                    @csrf
                    <x-ui.button type="submit" variant="accent" size="sm">এই Page কানেক্ট করুন</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    @empty
        <x-ui.card>
            <p class="text-sm text-mute">আপনার Facebook অ্যাকাউন্টে কোনো Page পাওয়া যায়নি — অ্যাকাউন্টটি কমপক্ষে একটি Facebook Page-এর Admin কিনা যাচাই করুন।</p>
        </x-ui.card>
    @endforelse
</div>

<div class="mt-6">
    <x-ui.button href="{{ route('tenant.settings') }}" variant="outline" size="sm">সেটিংসে ফিরে যান</x-ui.button>
</div>
@endsection
