@extends('layouts.panel')

@section('title', 'ক্যাটাগরি')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">ক্যাটাগরি</h1>

<form method="POST" action="{{ route('tenant.categories.store') }}" class="flex gap-3 mb-6 max-w-md">
    @csrf
    <input name="name" required placeholder="নতুন ক্যাটাগরির নাম"
           class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
    <x-ui.button type="submit" variant="accent" size="sm">যোগ করুন</x-ui.button>
</form>

<x-ui.card padding="none" class="max-w-2xl">
    @forelse ($categories as $cat)
        <div class="flex items-center justify-between px-4 py-3 border-b border-ink/5 last:border-0">
            <span class="flex items-center gap-2">
                <i data-lucide="folder" class="w-4 h-4 text-mute"></i>
                {{ $cat->name }} <span class="text-mute text-xs">({{ $cat->products_count }}টি প্রোডাক্ট)</span>
            </span>
            <form method="POST" action="{{ route('tenant.categories.destroy', $cat) }}" onsubmit="return confirm('মুছে ফেলবেন?')">
                @csrf @method('DELETE')
                <button class="text-red-600 text-xs hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">মুছুন</button>
            </form>
        </div>
    @empty
        <div class="px-4 py-12 text-center text-mute text-sm">
            <i data-lucide="folder-plus" class="w-7 h-7 mx-auto mb-2 text-mute/40"></i>
            কোনো ক্যাটাগরি নেই।
        </div>
    @endforelse
</x-ui.card>
@endsection
