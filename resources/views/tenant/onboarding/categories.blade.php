@extends('layouts.onboarding')

@section('title', 'পণ্যের ক্যাটাগরি')

@section('content')
<h1 class="font-disp font-bold text-xl sm:text-2xl mb-1.5">আমরা কিছু ক্যাটাগরি তৈরি করে দিয়েছি</h1>
<p class="text-mute text-sm mb-6">
    @if ($businessType)
        {{ $businessType->name_bn }} ব্যবসার জন্য সাধারণত যেসব ক্যাটাগরি লাগে। অপ্রয়োজনীয়গুলো মুছে দিন বা নিজের মতো যোগ করুন।
    @else
        আপনি চাইলে এখনই কিছু ক্যাটাগরি যোগ করতে পারেন, অথবা এই ধাপ পরে করতে পারবেন।
    @endif
</p>

@if (session('success'))
    <p class="mb-4 bg-leaf/10 border border-leaf/30 text-leafdk text-sm rounded-lg p-3">{{ session('success') }}</p>
@endif

<div class="flex flex-wrap gap-2 mb-5">
    @forelse ($categories as $category)
        <span class="inline-flex items-center gap-1.5 bg-paper border border-ink/10 rounded-pill pl-3 pr-1.5 py-1.5 text-sm">
            {{ $category->name }}
            <form method="POST" action="{{ route('tenant.categories.destroy', $category) }}" class="inline">
                @csrf @method('DELETE')
                <button type="submit" class="w-5 h-5 rounded-full hover:bg-red-100 text-mute hover:text-red-600 inline-flex items-center justify-center" title="মুছুন">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i>
                </button>
            </form>
        </span>
    @empty
        <p class="text-mute text-sm">এখনো কোনো ক্যাটাগরি নেই।</p>
    @endforelse
</div>

<form method="POST" action="{{ route('tenant.categories.store') }}" class="flex gap-2 mb-6">
    @csrf
    <input type="text" name="name" maxlength="100" required placeholder="নতুন ক্যাটাগরির নাম"
           class="flex-1 rounded-lg border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
    <button type="submit" class="px-4 rounded-lg border border-leaf text-leafdk font-semibold text-sm hover:bg-leaf/5">+ যোগ করুন</button>
</form>

<form method="POST" action="{{ route('tenant.onboarding.categories.continue') }}">
    @csrf
    <button type="submit" class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
        চালিয়ে যান
    </button>
</form>
@endsection
