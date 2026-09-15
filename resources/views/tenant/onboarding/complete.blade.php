@extends('layouts.onboarding')

@section('title', 'স্টোর প্রস্তুত!')

@section('content')
<div class="text-center">
    <div class="text-5xl mb-3">🎉</div>
    <h1 class="font-disp font-bold text-2xl mb-1.5">আপনার স্টোর প্রস্তুত!</h1>
    <p class="text-mute text-sm mb-6">{{ $tenant->store_name }} এখন লাইভ — চাইলে যেকোনো সময় আরও যোগ করতে পারবেন।</p>

    <div class="grid grid-cols-3 gap-2 mb-8 text-left">
        <div class="rounded-xl border border-ink/10 p-3">
            <p class="text-2xl font-bold text-leafdk">{{ $tenant->businessType?->icon ?? '🏪' }}</p>
            <p class="text-xs text-mute mt-1">{{ $tenant->businessType?->name_bn ?? 'ব্যবসার ধরন' }}</p>
        </div>
        <div class="rounded-xl border border-ink/10 p-3">
            <p class="text-2xl font-bold text-leafdk">{{ $categoryCount }}</p>
            <p class="text-xs text-mute mt-1">ক্যাটাগরি তৈরি</p>
        </div>
        <div class="rounded-xl border border-ink/10 p-3">
            <p class="text-2xl font-bold text-leafdk">{{ $productCount }}</p>
            <p class="text-xs text-mute mt-1">প্রোডাক্ট</p>
        </div>
    </div>

    <form method="POST" action="{{ route('tenant.onboarding.complete') }}">
        @csrf
        <button type="submit" class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
            ড্যাশবোর্ডে যান
        </button>
    </form>
</div>
@endsection
