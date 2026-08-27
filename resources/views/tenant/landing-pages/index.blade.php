@extends('layouts.panel')

@section('title', 'ল্যান্ডিং পেজ')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">ল্যান্ডিং পেজ</h1>
        <p class="text-sm text-mute">একটি প্রোডাক্টের জন্য আলাদা সেলস পেজ বানান — ফেসবুক/মেসেঞ্জার থেকে সরাসরি অর্ডার নিতে</p>
    </div>
    <a href="{{ route('tenant.landing-pages.create') }}" class="px-4 py-2.5 rounded-btn bg-leaf text-white font-semibold text-sm hover:bg-leafdk transition">+ নতুন ল্যান্ডিং পেজ</a>
</div>

@if ($pages->isEmpty())
    <x-ui.card class="text-center py-12">
        <p class="text-4xl mb-2">🚀</p>
        <p class="font-semibold">এখনো কোনো ল্যান্ডিং পেজ নেই</p>
        <p class="text-sm text-mute mt-1">একটি প্রোডাক্ট বেছে নিয়ে সেলস পেজ তৈরি করুন</p>
    </x-ui.card>
@else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($pages as $page)
            <x-ui.card class="space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold truncate">{{ $page->title }}</p>
                        <p class="text-xs text-mute truncate">{{ $page->product?->name ?? 'প্রোডাক্ট মুছে ফেলা হয়েছে' }}</p>
                    </div>
                    <span class="shrink-0 text-xs font-semibold px-2 py-1 rounded-full {{ $page->isPublished() ? 'bg-leaf/10 text-leafdk' : 'bg-ink/5 text-mute' }}">
                        {{ $page->isPublished() ? '● পাবলিশড' : '○ ড্রাফট' }}
                    </span>
                </div>

                <p class="text-xs text-mute break-all">/l/{{ $page->slug }}</p>

                <div class="flex flex-wrap gap-2 pt-1">
                    <a href="{{ route('tenant.landing-pages.edit', $page) }}" class="px-3 py-1.5 rounded-btn border border-ink/15 text-xs font-semibold hover:bg-paper">✏️ এডিট</a>
                    <a href="{{ $tenant->url() }}/l/{{ $page->slug }}" target="_blank" class="px-3 py-1.5 rounded-btn border border-ink/15 text-xs font-semibold hover:bg-paper">👁 প্রিভিউ</a>

                    @if ($page->isPublished())
                        <form method="POST" action="{{ route('tenant.landing-pages.unpublish', $page) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-btn border border-amber/40 text-amber text-xs font-semibold hover:bg-amber/10">আনপাবলিশ</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('tenant.landing-pages.publish', $page) }}">
                            @csrf
                            <button class="px-3 py-1.5 rounded-btn bg-leaf text-white text-xs font-semibold hover:bg-leafdk">পাবলিশ করুন</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('tenant.landing-pages.destroy', $page) }}" onsubmit="return confirm('এই পেজটি স্থায়ীভাবে মুছে যাবে, নিশ্চিত?');">
                        @csrf @method('DELETE')
                        <button class="px-3 py-1.5 rounded-btn border border-red-200 text-red-600 text-xs font-semibold hover:bg-red-50">মুছুন</button>
                    </form>
                </div>
            </x-ui.card>
        @endforeach
    </div>
@endif
@endsection
