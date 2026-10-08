@extends('layouts.super')

@section('title', 'Homepage — Brand platform')

@section('content')
@include('super.platform._tabs')
@php
    $box = 'rounded-xl border border-ink/5 bg-white p-4 sm:p-5';
    $field = 'mt-1 w-full rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm';
    $lbl = 'block text-sm font-semibold';
    $val = fn ($k) => old($k, $settings[$k]);
@endphp
<div class="mb-1 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-disp text-2xl font-bold">🏠 Homepage</h1>
    <a href="{{ url('/') }}" target="_blank" class="rounded-lg border border-ink/10 bg-white px-3 py-2 text-sm font-semibold">View homepage ↗</a>
</div>
<p class="mb-5 text-sm text-mute">The few homepage settings that change over time. Brand rows need no setup here — see the right-hand panel.</p>
@if($errors->any())<div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

<div class="grid gap-5 xl:grid-cols-[1fr_360px]">
    <form method="POST" action="{{ route('super.homepage.update') }}" class="space-y-5">
        @csrf @method('PUT')
        <section class="{{ $box }} space-y-4">
            <h2 class="font-bold">Hero & announcement</h2>
            <label class="{{ $lbl }}">Top announcement strip<input name="announcement" value="{{ $val('announcement') }}" required maxlength="160" class="{{ $field }}"></label>
            <label class="{{ $lbl }}">Hero pill (small label above the headline)<input name="hero_pill" value="{{ $val('hero_pill') }}" required maxlength="120" class="{{ $field }}"></label>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="{{ $lbl }}">Headline<input name="hero_title" value="{{ $val('hero_title') }}" required maxlength="120" class="{{ $field }}"></label>
                <label class="{{ $lbl }}">Headline — gold part<input name="hero_highlight" value="{{ $val('hero_highlight') }}" maxlength="80" class="{{ $field }}"></label>
            </div>
            <label class="{{ $lbl }}">Hero text<textarea name="hero_sub" rows="3" required maxlength="400" class="{{ $field }}">{{ $val('hero_sub') }}</textarea></label>
        </section>

        <section class="{{ $box }} space-y-4">
            <h2 class="font-bold">Awards & voting on the homepage</h2>
            <label class="{{ $lbl }}">Featured award card
                <select name="award_id" class="{{ $field }}">
                    <option value="">Automatic — latest award marked “featured on homepage” (sample card if none)</option>
                    @foreach($awards as $a)<option value="{{ $a->id }}" @selected((string) $val('award_id') === (string) $a->id)>{{ $a->title }} · {{ $a->statusLabel() }}</option>@endforeach
                </select>
                <span class="mt-1 block text-xs font-normal text-mute">Draft awards are never shown publicly, so they aren’t listed.</span>
            </label>
            <label class="{{ $lbl }}">Live voting section
                <select name="campaign_id" class="{{ $field }}">
                    <option value="">Automatic — the open campaign with the most nominees (sample tabs if none)</option>
                    @foreach($campaigns as $c)<option value="{{ $c->id }}" @selected((string) $val('campaign_id') === (string) $c->id)>{{ $c->title }} · {{ $c->phaseLabel() }}</option>@endforeach
                </select>
            </label>
        </section>

        <section class="{{ $box }} space-y-3">
            <h2 class="font-bold">Sample content</h2>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="sample_fallback" value="1" @checked($val('sample_fallback')) class="mt-0.5">
                <span><b>Fill empty brand slots with sample brands.</b> Real approved brands always come first and replace samples automatically; samples disappear once there are enough real brands. Turn off to show real brands only.</span></label>
            <p class="pt-1 text-sm font-semibold">Optional sample sections</p>
            @foreach(['show_stories' => 'Stories / media', 'show_events' => 'Events', 'show_sponsors' => 'Sponsors'] as $k => $l)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="{{ $k }}" value="1" @checked($val($k))> Show “{{ $l }}” section</label>
            @endforeach
            <p class="text-xs text-mute">These sections have no live data yet; hiding one also removes its menu links.</p>
        </section>

        <div class="flex flex-wrap gap-2">
            <button class="rounded-lg bg-leaf px-5 py-2.5 text-sm font-semibold text-white">Save homepage</button>
        </div>
    </form>

    <aside class="space-y-5">
        <section class="{{ $box }}">
            <h2 class="font-bold">🔄 Newly listed brands — automatic</h2>
            <p class="mt-1 text-sm text-mute">Every approved brand appears here automatically, newest first. No featuring or payment needed.</p>
            <p class="mt-2 text-sm"><b>{{ $approvedCount }}</b> approved {{ Str::plural('brand', $approvedCount) }} · <a href="{{ route('super.brands.index', ['status' => 'pending']) }}" class="text-leaf hover:underline">review pending</a></p>
        </section>

        <section class="{{ $box }}">
            <h2 class="font-bold">⭐ Featured brands — manual (editorial)</h2>
            <p class="mt-1 text-sm text-mute">Set on each brand’s page (Feature / order). Never sold.</p>
            <ul class="mt-2 space-y-1 text-sm">
                @forelse($featured as $b)
                    <li><a href="{{ route('super.brands.show', $b) }}" class="text-leaf hover:underline">{{ $b->name }}</a> <span class="text-xs text-mute">· order {{ $b->featured_order }}</span></li>
                @empty
                    <li class="text-mute">None yet — sample featured brands fill the row.</li>
                @endforelse
            </ul>
        </section>

        <section class="{{ $box }}">
            <h2 class="font-bold">💳 Sponsored — manual (paid)</h2>
            <p class="mt-1 text-sm text-mute">Shown in the Featured row, always labelled “Sponsored”. Never grants verification or awards.</p>
            <ul class="mt-2 space-y-1 text-sm">
                @forelse($sponsored as $b)
                    <li><a href="{{ route('super.brands.show', $b) }}" class="text-leaf hover:underline">{{ $b->name }}</a> <span class="text-xs text-mute">· {{ $b->sponsored_until ? 'until '.$b->sponsored_until->format('d M Y') : 'no end date' }}</span></li>
                @empty
                    <li class="text-mute">None.</li>
                @endforelse
            </ul>
        </section>

        <form method="POST" action="{{ route('super.homepage.reset') }}" onsubmit="return confirm('Reset homepage settings to defaults?')">@csrf
            <button class="text-sm text-mute hover:text-ink">Reset to defaults</button>
        </form>
    </aside>
</div>
@endsection
