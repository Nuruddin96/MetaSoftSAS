@extends('layouts.panel')

@section('title', 'সেকশন সাজান')

@section('content')
<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div class="min-w-0">
        <a href="{{ route('tenant.landing-pages.index') }}" class="text-sm text-mute hover:text-ink">← সব ল্যান্ডিং পেজ</a>
        <h1 class="font-disp font-bold text-2xl mt-1 truncate">{{ $landingPage->title }}</h1>
        <p class="text-sm text-mute">প্রোডাক্টঃ {{ $landingPage->product?->name }} · <span class="break-all">/l/{{ $landingPage->slug }}</span></p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('tenant.landing-pages.design', $landingPage) }}"
           class="px-4 py-2.5 rounded-btn border border-ink/15 font-semibold text-sm hover:bg-white transition">🎨 ডিজাইন</a>
        <a href="{{ $tenant->url() }}/l/{{ $landingPage->slug }}" target="_blank"
           class="px-4 py-2.5 rounded-btn border border-ink/15 font-semibold text-sm hover:bg-white transition">👁 নতুন ট্যাবে দেখুন</a>

        @if ($landingPage->isPublished())
            <form method="POST" action="{{ route('tenant.landing-pages.unpublish', $landingPage) }}">
                @csrf
                <button class="px-4 py-2.5 rounded-btn border border-amber/40 text-amber font-semibold text-sm hover:bg-amber/10">আনপাবলিশ করুন</button>
            </form>
        @else
            <form method="POST" action="{{ route('tenant.landing-pages.publish', $landingPage) }}">
                @csrf
                <button class="px-4 py-2.5 rounded-btn bg-leaf text-white font-semibold text-sm hover:bg-leafdk">🚀 পাবলিশ করুন</button>
            </form>
        @endif
    </div>
</div>

{{-- Title --}}
<x-ui.card class="mb-6">
    <form method="POST" action="{{ route('tenant.landing-pages.update', $landingPage) }}" class="flex flex-wrap items-end gap-3">
        @csrf @method('PUT')
        <div class="flex-1 min-w-[200px]">
            <label class="text-sm font-medium">পেজের নাম (শুধু আপনার জন্য, কাস্টমার দেখবে না)</label>
            <input name="title" value="{{ $landingPage->title }}" required
                   class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
        </div>
        <x-ui.button type="submit" variant="outline" size="sm">সেভ করুন</x-ui.button>
    </form>
</x-ui.card>

<div class="grid xl:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
    {{-- Sections --}}
    <div id="sectionsSortable" data-reorder-url="{{ route('tenant.landing-pages.sections.reorder', $landingPage) }}" data-csrf="{{ csrf_token() }}" class="space-y-4">
        @forelse ($landingPage->sections ?? [] as $i => $section)
            <x-ui.card class="space-y-4 {{ ($section['hidden'] ?? false) ? 'opacity-50' : '' }}" id="section-{{ $section['id'] }}" data-section-id="{{ $section['id'] }}">
                <div class="flex items-center justify-between gap-2 pb-3 border-b border-ink/10">
                    <p class="font-semibold text-sm flex items-center gap-2">
                        <span class="drag-handle text-mute select-none" title="টেনে সাজান">⠿</span>
                        {{ $i + 1 }}. {{ $sectionTypes[$section['type']] ?? $section['type'] }}
                        @if ($section['hidden'] ?? false)
                            <span class="text-xs font-normal text-mute">(লুকানো)</span>
                        @endif
                    </p>
                    <div class="flex items-center gap-1">
                        <form method="POST" action="{{ route('tenant.landing-pages.sections.move', [$landingPage, $section['id']]) }}">
                            @csrf
                            <input type="hidden" name="direction" value="up">
                            <button class="w-8 h-8 rounded-btn hover:bg-paper text-ink/60 disabled:opacity-30" @disabled($i === 0) title="উপরে সরান">▲</button>
                        </form>
                        <form method="POST" action="{{ route('tenant.landing-pages.sections.move', [$landingPage, $section['id']]) }}">
                            @csrf
                            <input type="hidden" name="direction" value="down">
                            <button class="w-8 h-8 rounded-btn hover:bg-paper text-ink/60 disabled:opacity-30" @disabled($i === count($landingPage->sections) - 1) title="নিচে সরান">▼</button>
                        </form>
                        <form method="POST" action="{{ route('tenant.landing-pages.sections.toggle', [$landingPage, $section['id']]) }}">
                            @csrf
                            <button class="w-8 h-8 rounded-btn hover:bg-paper text-ink/60" title="{{ ($section['hidden'] ?? false) ? 'আবার দেখান' : 'লুকান' }}">{{ ($section['hidden'] ?? false) ? '🙈' : '👁' }}</button>
                        </form>
                        <form method="POST" action="{{ route('tenant.landing-pages.sections.duplicate', [$landingPage, $section['id']]) }}">
                            @csrf
                            <button class="w-8 h-8 rounded-btn hover:bg-paper text-ink/60" title="কপি করুন">⧉</button>
                        </form>
                        <form method="POST" action="{{ route('tenant.landing-pages.sections.destroy', [$landingPage, $section['id']]) }}" onsubmit="return confirm('এই সেকশনটি মুছে যাবে, নিশ্চিত?');">
                            @csrf @method('DELETE')
                            <button class="w-8 h-8 rounded-btn hover:bg-red-50 text-red-500" title="মুছুন">🗑</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('tenant.landing-pages.sections.update', [$landingPage, $section['id']]) }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf @method('PUT')
                    @include('tenant.landing-pages.sections._' . $section['type'], ['data' => $section['data'] ?? []])
                    <x-ui.button type="submit" variant="accent" size="sm">এই সেকশন সেভ করুন</x-ui.button>
                </form>
            </x-ui.card>
        @empty
            <x-ui.card class="text-center py-10 text-mute">এখনো কোনো সেকশন নেই — নিচ থেকে একটি যোগ করুন</x-ui.card>
        @endforelse

        {{-- Add section --}}
        <x-ui.card>
            <form method="POST" action="{{ route('tenant.landing-pages.sections.add', $landingPage) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="flex-1 min-w-[200px]">
                    <label class="text-sm font-medium">নতুন সেকশন যোগ করুন</label>
                    <select name="type" required class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                        @foreach ($sectionTypes as $type => $label)
                            <option value="{{ $type }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <x-ui.button type="submit" variant="outline" size="sm">+ সেকশন যোগ করুন</x-ui.button>
            </form>
        </x-ui.card>
    </div>

    {{-- Live preview --}}
    <div class="hidden xl:block sticky top-4">
        <x-ui.card class="p-3">
            <div class="flex items-center justify-center gap-1.5 mb-3">
                <button type="button" data-preview-width="100%" class="is-active px-3 py-1.5 rounded-btn text-xs font-semibold border border-ink/15">🖥 ডেস্কটপ</button>
                <button type="button" data-preview-width="768px" class="px-3 py-1.5 rounded-btn text-xs font-semibold border border-ink/15">📱 ট্যাবলেট</button>
                <button type="button" data-preview-width="375px" class="px-3 py-1.5 rounded-btn text-xs font-semibold border border-ink/15">📱 মোবাইল</button>
            </div>
            <div class="rounded-btn overflow-hidden border border-ink/10 bg-paper" style="height: 70vh; overflow: auto;">
                <iframe id="previewFrame" src="{{ $tenant->url() }}/l/{{ $landingPage->slug }}" class="h-full mx-auto bg-white" style="width: 100%; min-height: 70vh;"></iframe>
            </div>
            <p class="text-xs text-mute mt-2 text-center">প্রতিবার সেকশন সেভ করলে প্রিভিউ রিফ্রেশ করতে পেজটি রিলোড করুন</p>
        </x-ui.card>
    </div>
</div>
@endsection
