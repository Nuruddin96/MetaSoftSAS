@extends('layouts.panel')

@section('title', 'অ্যাট্রিবিউট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">অ্যাট্রিবিউট</h1>
<p class="text-mute text-sm mb-6">রঙ, সাইজ, স্টোরেজ ইত্যাদি — প্রোডাক্টের ভ্যারিয়েন্ট বানানোর সময় বেছে নেয়ার জন্য একবার তৈরি করে রাখুন।</p>

<form method="POST" action="{{ route('tenant.attributes.store') }}" class="flex gap-3 mb-6 max-w-md">
    @csrf
    <input name="name" required placeholder="যেমন: রঙ, সাইজ, স্টোরেজ"
           class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
    <x-ui.button type="submit" variant="accent" size="sm">যোগ করুন</x-ui.button>
</form>

<div class="grid gap-4 max-w-2xl">
    @forelse ($attributes as $attribute)
        <x-ui.card padding="default">
            <div class="flex items-center justify-between mb-1">
                <span class="font-semibold text-sm">{{ $attribute->name }}</span>
                <span class="flex items-center gap-3 text-xs">
                    <button type="button" onclick="document.getElementById('edit-attr-{{ $attribute->id }}').classList.toggle('hidden')"
                            class="text-leafdk hover:underline">এডিট</button>
                    <form method="POST" action="{{ route('tenant.attributes.destroy', $attribute) }}" onsubmit="return confirm('মুছে ফেলবেন? — এর সবগুলো ভ্যালুও মুছে যাবে।')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 hover:underline">মুছুন</button>
                    </form>
                </span>
            </div>
            <div id="edit-attr-{{ $attribute->id }}" class="hidden mb-3">
                <form method="POST" action="{{ route('tenant.attributes.update', $attribute) }}" class="flex gap-2">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $attribute->name }}" required
                           class="flex-1 rounded-btn border border-ink/15 px-3 py-2 text-xs focus:ring-2 focus:ring-leaf outline-none">
                    <button class="rounded-btn border border-ink/15 px-3 py-2 text-xs hover:bg-ink/5">সেভ করুন</button>
                </form>
            </div>

            <div class="flex flex-wrap gap-2 mb-3">
                @forelse ($attribute->values as $value)
                    <span class="inline-flex items-center gap-1.5 rounded-pill bg-ink/5 px-2.5 py-1 text-xs">
                        <button type="button" onclick="document.getElementById('edit-val-{{ $value->id }}').classList.toggle('hidden')"
                                class="hover:underline">{{ $value->value }}</button>
                        <form method="POST" action="{{ route('tenant.attributes.values.destroy', $value) }}" class="inline">
                            @csrf @method('DELETE')
                            <button class="text-mute hover:text-red-600" aria-label="মুছুন">×</button>
                        </form>
                    </span>
                @empty
                    <span class="text-mute text-xs">এখনো কোনো ভ্যালু নেই।</span>
                @endforelse
            </div>

            @foreach ($attribute->values as $value)
                <div id="edit-val-{{ $value->id }}" class="hidden mb-2">
                    <form method="POST" action="{{ route('tenant.attributes.values.update', $value) }}" class="flex gap-2">
                        @csrf @method('PUT')
                        <input name="value" value="{{ $value->value }}" required
                               class="flex-1 rounded-btn border border-ink/15 px-3 py-2 text-xs focus:ring-2 focus:ring-leaf outline-none">
                        <button class="rounded-btn border border-ink/15 px-3 py-2 text-xs hover:bg-ink/5">সেভ করুন</button>
                    </form>
                </div>
            @endforeach

            <form method="POST" action="{{ route('tenant.attributes.values.store', $attribute) }}" class="flex gap-2">
                @csrf
                <input name="value" required placeholder="নতুন ভ্যালু, যেমন: লাল"
                       class="flex-1 rounded-btn border border-ink/15 px-3 py-2 text-xs focus:ring-2 focus:ring-leaf outline-none">
                <button class="rounded-btn border border-ink/15 px-3 py-2 text-xs hover:bg-ink/5">যোগ করুন</button>
            </form>
        </x-ui.card>
    @empty
        <div class="px-4 py-12 text-center text-mute text-sm">
            <i data-lucide="tag" class="w-7 h-7 mx-auto mb-2 text-mute/40"></i>
            কোনো অ্যাট্রিবিউট নেই।
        </div>
    @endforelse
</div>
@endsection
