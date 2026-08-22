@extends('layouts.panel')

@section('title', 'ক্যাটাগরি')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-6">ক্যাটাগরি</h1>

<form method="POST" action="{{ route('tenant.categories.store') }}" class="flex flex-wrap gap-3 mb-6 max-w-2xl">
    @csrf
    <input name="name" required placeholder="নতুন ক্যাটাগরি/সাব-ক্যাটাগরির নাম"
           class="flex-1 min-w-[200px] rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
    <select name="parent_id" class="rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
        <option value="">মূল ক্যাটাগরি (কোনো প্যারেন্ট নেই)</option>
        @foreach ($categories as $top)
            <option value="{{ $top->id }}">— "{{ $top->name }}"-এর সাব-ক্যাটাগরি</option>
        @endforeach
    </select>
    <x-ui.button type="submit" variant="accent" size="sm">যোগ করুন</x-ui.button>
</form>

<x-ui.card padding="none" class="max-w-2xl">
    @forelse ($categories as $cat)
        <div class="border-b border-ink/5 last:border-0">
            <div class="flex items-center justify-between px-4 py-3">
                <span class="flex items-center gap-2">
                    <i data-lucide="folder" class="w-4 h-4 text-mute"></i>
                    {{ $cat->name }} <span class="text-mute text-xs">({{ $cat->products_count }}টি প্রোডাক্ট)</span>
                </span>
                <span class="flex items-center gap-3 text-xs">
                    <button type="button" onclick="document.getElementById('edit-cat-{{ $cat->id }}').classList.toggle('hidden')"
                            class="text-leafdk hover:underline">এডিট</button>
                    <form method="POST" action="{{ route('tenant.categories.destroy', $cat) }}" onsubmit="return confirm('মুছে ফেলবেন? — এর প্রোডাক্টগুলো ক্যাটাগরি-বিহীন হয়ে যাবে, মুছে যাবে না।')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 hover:underline rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2">মুছুন</button>
                    </form>
                </span>
            </div>
            <div id="edit-cat-{{ $cat->id }}" class="hidden px-4 pb-3">
                <form method="POST" action="{{ route('tenant.categories.update', $cat) }}" class="flex flex-wrap gap-2">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $cat->name }}" required
                           class="flex-1 min-w-[160px] rounded-btn border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
                    <x-ui.button type="submit" variant="outline" size="sm">সেভ করুন</x-ui.button>
                </form>
            </div>

            @if ($cat->children->isNotEmpty())
                <div class="pl-8 pb-1">
                    @foreach ($cat->children as $child)
                        <div class="flex items-center justify-between py-2 pr-4 border-t border-ink/5">
                            <span class="flex items-center gap-2 text-[13px]">
                                <i data-lucide="corner-down-right" class="w-3.5 h-3.5 text-mute"></i>
                                {{ $child->name }} <span class="text-mute text-xs">({{ $child->products_count ?? 0 }}টি প্রোডাক্ট)</span>
                            </span>
                            <span class="flex items-center gap-3 text-xs">
                                <button type="button" onclick="document.getElementById('edit-cat-{{ $child->id }}').classList.toggle('hidden')"
                                        class="text-leafdk hover:underline">এডিট</button>
                                <form method="POST" action="{{ route('tenant.categories.destroy', $child) }}" onsubmit="return confirm('মুছে ফেলবেন?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline">মুছুন</button>
                                </form>
                            </span>
                        </div>
                        <div id="edit-cat-{{ $child->id }}" class="hidden pb-2">
                            <form method="POST" action="{{ route('tenant.categories.update', $child) }}" class="flex flex-wrap gap-2">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $child->name }}" required
                                       class="flex-1 min-w-[160px] rounded-btn border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
                                <x-ui.button type="submit" variant="outline" size="sm">সেভ করুন</x-ui.button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div class="px-4 py-12 text-center text-mute text-sm">
            <i data-lucide="folder-plus" class="w-7 h-7 mx-auto mb-2 text-mute/40"></i>
            কোনো ক্যাটাগরি নেই।
        </div>
    @endforelse
</x-ui.card>
@endsection
