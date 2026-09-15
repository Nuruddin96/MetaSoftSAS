@extends('layouts.panel')

@section('title', 'গ্লোবাল ডিজাইন')

@php
    $d = $landingPage->design ?? [];
    $sel = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none bg-white';
    $lbl = 'text-sm font-medium';
@endphp

@section('content')
<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div class="min-w-0">
        <a href="{{ route('tenant.landing-pages.edit', $landingPage) }}" class="text-sm text-mute hover:text-ink">← সেকশনে ফিরে যান</a>
        <h1 class="font-disp font-bold text-2xl mt-1">গ্লোবাল ডিজাইন — {{ $landingPage->title }}</h1>
        <p class="text-sm text-mute">এই সেটিংস পুরো পেজের সব সেকশনের ডিফল্ট ডিজাইন হিসেবে কাজ করবে। কোনো সেকশনে আলাদা করে ডিজাইন সেট করা থাকলে সেটি অগ্রাধিকার পাবে।</p>
    </div>
</div>

<div class="grid xl:grid-cols-[minmax(0,1fr)_380px] gap-6 items-start">
    <form method="POST" action="{{ route('tenant.landing-pages.design.update', $landingPage) }}" class="space-y-6">
        @csrf @method('PUT')

        <x-ui.card class="space-y-4">
            <h2 class="font-semibold">🎨 ব্র্যান্ড কালার</h2>
            <p class="text-xs text-mute -mt-2">খালি রাখলে আপনার স্টোরের ডিফল্ট কালার ব্যবহার হবে (Settings → Branding)</p>
            <div class="grid sm:grid-cols-2 gap-3">
                @foreach ([
                    'primary_color' => 'প্রাইমারি কালার',
                    'secondary_color' => 'সেকেন্ডারি/অ্যাকসেন্ট কালার',
                    'background_color' => 'পেজের ব্যাকগ্রাউন্ড কালার',
                    'text_color' => 'সাধারণ লেখার কালার',
                ] as $key => $label)
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="design[brand][{{ $key }}_enabled]" value="1" id="b_{{ $key }}" @checked(!empty($d['brand'][$key]))>
                        <label for="b_{{ $key }}" class="text-sm flex-1">{{ $label }}</label>
                        <input type="color" name="design[brand][{{ $key }}]" value="{{ $d['brand'][$key] ?? '#128155' }}" class="w-10 h-8 rounded border border-ink/15">
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h2 class="font-semibold">🔤 টাইপোগ্রাফি</h2>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lbl }}">হেডিং ফন্ট স্টাইল</label>
                    <select name="design[typography][heading_font]" class="{{ $sel }}">
                        <option value="display" @selected(($d['typography']['heading_font'] ?? 'display') === 'display')>ক্লাসিক (সেরিফ)</option>
                        <option value="modern" @selected(($d['typography']['heading_font'] ?? '') === 'modern')>মডার্ন (সান-সেরিফ)</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">লেখার সাইজ (ডিফল্ট)</label>
                    <select name="design[typography][font_size]" class="{{ $sel }}">
                        @foreach (['sm' => 'ছোট', 'base' => 'মাঝারি', 'lg' => 'বড়'] as $k => $v)
                            <option value="{{ $k }}" @selected(($d['typography']['font_size'] ?? 'base') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">লেখার ওজন (Font Weight)</label>
                    <select name="design[typography][font_weight]" class="{{ $sel }}">
                        @foreach (['normal' => 'স্বাভাবিক', 'semibold' => 'মাঝারি বোল্ড', 'bold' => 'বোল্ড'] as $k => $v)
                            <option value="{{ $k }}" @selected(($d['typography']['font_weight'] ?? 'normal') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">লাইন হাইট</label>
                    <select name="design[typography][line_height]" class="{{ $sel }}">
                        @foreach (['tight' => 'কম', 'normal' => 'স্বাভাবিক', 'relaxed' => 'বেশি'] as $k => $v)
                            <option value="{{ $k }}" @selected(($d['typography']['line_height'] ?? 'normal') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h2 class="font-semibold">🔘 বাটন</h2>
            <div class="grid sm:grid-cols-3 gap-3">
                <div>
                    <label class="{{ $lbl }}">স্টাইল</label>
                    <select name="design[buttons][style]" class="{{ $sel }}">
                        <option value="solid" @selected(($d['buttons']['style'] ?? 'solid') === 'solid')>সলিড</option>
                        <option value="outline" @selected(($d['buttons']['style'] ?? '') === 'outline')>আউটলাইন</option>
                        <option value="ghost" @selected(($d['buttons']['style'] ?? '') === 'ghost')>ঘোস্ট</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">কর্নার</label>
                    <select name="design[buttons][radius]" class="{{ $sel }}">
                        <option value="none" @selected(($d['buttons']['radius'] ?? '') === 'none')>শার্প</option>
                        <option value="md" @selected(($d['buttons']['radius'] ?? 'md') === 'md')>রাউন্ড</option>
                        <option value="full" @selected(($d['buttons']['radius'] ?? '') === 'full')>পিল</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">সাইজ</label>
                    <select name="design[buttons][size]" class="{{ $sel }}">
                        @foreach (['sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়'] as $k => $v)
                            <option value="{{ $k }}" @selected(($d['buttons']['size'] ?? 'md') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card class="space-y-4">
            <h2 class="font-semibold">📐 গ্লোবাল লেআউট</h2>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lbl }}">কন্টেইনার প্রস্থ</label>
                    <select name="design[global][container_width]" class="{{ $sel }}">
                        <option value="narrow" @selected(($d['global']['container_width'] ?? '') === 'narrow')>সংকীর্ণ</option>
                        <option value="normal" @selected(($d['global']['container_width'] ?? 'normal') === 'normal')>স্বাভাবিক</option>
                        <option value="wide" @selected(($d['global']['container_width'] ?? '') === 'wide')>প্রশস্ত</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">সেকশনের মাঝে স্পেস</label>
                    <select name="design[global][section_spacing]" class="{{ $sel }}">
                        <option value="compact" @selected(($d['global']['section_spacing'] ?? '') === 'compact')>কম্প্যাক্ট</option>
                        <option value="normal" @selected(($d['global']['section_spacing'] ?? 'normal') === 'normal')>স্বাভাবিক</option>
                        <option value="spacious" @selected(($d['global']['section_spacing'] ?? '') === 'spacious')>প্রশস্ত</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">ডিফল্ট বর্ডার রেডিয়াস</label>
                    <select name="design[global][border_radius]" class="{{ $sel }}">
                        <option value="none" @selected(($d['global']['border_radius'] ?? '') === 'none')>শার্প</option>
                        <option value="md" @selected(($d['global']['border_radius'] ?? 'md') === 'md')>রাউন্ড</option>
                        <option value="lg" @selected(($d['global']['border_radius'] ?? '') === 'lg')>এক্সট্রা রাউন্ড</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">ডিফল্ট শ্যাডো</label>
                    <select name="design[global][shadow]" class="{{ $sel }}">
                        @foreach (['none' => 'নেই', 'sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়'] as $k => $v)
                            <option value="{{ $k }}" @selected(($d['global']['shadow'] ?? 'none') === $k)>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-ui.card>

        <x-ui.button type="submit" variant="accent" size="sm">ডিজাইন সেভ করুন</x-ui.button>
    </form>

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
            <p class="text-xs text-mute mt-2 text-center">সেভ করার পর প্রিভিউ রিফ্রেশ করতে পেজটি রিলোড করুন</p>
        </x-ui.card>
    </div>
</div>
@endsection
