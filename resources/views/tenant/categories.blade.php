@extends('layouts.panel')

@section('title', 'ক্যাটাগরি')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">ক্যাটাগরি</h1>

<form method="POST" action="{{ route('tenant.categories.store') }}" class="flex gap-3 mb-6 max-w-md">
    @csrf
    <input name="name" required placeholder="নতুন ক্যাটাগরির নাম"
           class="flex-1 rounded-lg border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
    <button class="px-4 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">যোগ করুন</button>
</form>

<div class="bg-white rounded-xl border border-ink/5 max-w-2xl">
    @forelse ($categories as $cat)
        <div class="flex items-center justify-between px-4 py-3 border-b border-ink/5 last:border-0">
            <span>{{ $cat->name }} <span class="text-mute text-xs">({{ $cat->products_count }}টি প্রোডাক্ট)</span></span>
            <form method="POST" action="{{ route('tenant.categories.destroy', $cat) }}" onsubmit="return confirm('মুছে ফেলবেন?')">
                @csrf @method('DELETE')
                <button class="text-red-600 text-xs hover:underline">মুছুন</button>
            </form>
        </div>
    @empty
        <p class="px-4 py-10 text-center text-mute text-sm">কোনো ক্যাটাগরি নেই।</p>
    @endforelse
</div>
@endsection
