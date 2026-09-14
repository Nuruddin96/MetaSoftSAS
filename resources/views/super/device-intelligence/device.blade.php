@extends('layouts.super')
@section('title', ($device->device_model ?: 'ডিভাইস').' — ডিভাইস ইন্টেলিজেন্স')
@section('content')
@php
    $activationBadge = [
        'inactive' => ['bg-ink/5 text-mute', 'নিষ্ক্রিয়'],
        'waiting_for_android_access' => ['bg-amber/10 text-amber', 'অ্যান্ড্রয়েড অনুমতির অপেক্ষায়'],
        'disabled_by_tenant' => ['bg-red-50 text-red-600', 'টেনেন্ট কর্তৃক বন্ধ'],
        'active' => ['bg-leaf/10 text-leafdk', 'সক্রিয়'],
    ];
    $accessBadge = [
        'granted' => ['bg-leaf/10 text-leafdk', 'দেওয়া আছে'],
        'denied' => ['bg-red-50 text-red-600', 'দেওয়া নেই'],
        'restricted' => ['bg-amber/10 text-amber', 'সীমাবদ্ধ'],
        'not_supported' => ['bg-ink/5 text-mute', 'প্রযোজ্য নয়'],
        'not_requested' => ['bg-ink/5 text-mute', 'চাওয়া হয়নি'],
    ];
    $featureLabels = [
        'notification_monitoring' => 'নোটিফিকেশন মনিটরিং',
        'app_usage' => 'অ্যাপ ব্যবহার',
        'device_health' => 'ডিভাইস স্বাস্থ্য',
        'location' => 'লোকেশন',
    ];
    $tabs = [
        'overview' => 'Overview',
        'notifications' => 'Notifications & Messaging',
        'usage' => 'App Usage',
        'health' => 'Device Health',
        'permissions' => 'Permissions / Access',
        'history' => 'History',
    ];
    $bytesToMb = fn ($b) => $b === null ? '—' : number_format($b / 1048576, 0).' MB';
@endphp

<a href="{{ route('super.device-intelligence.show', $tenant) }}" class="text-mute text-sm hover:underline">← {{ $tenant->store_name }}</a>
<h1 class="font-disp font-bold text-2xl mt-1 mb-1">{{ $device->device_model ?: 'অজানা মডেল' }}</h1>
<p class="text-mute text-xs mb-6">{{ $device->user?->name }} · Android {{ $device->os_version }} · v{{ $device->app_version }} · {{ Str::limit($device->device_uuid, 20) }}</p>

<div class="flex gap-1 mb-5 border-b border-ink/5 overflow-x-auto">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('super.device-intelligence.devices.show', [$tenant, $device]) }}?tab={{ $key }}"
           class="px-3 py-2 text-sm whitespace-nowrap {{ $tab === $key ? 'border-b-2 border-leafdk text-leafdk font-medium' : 'text-mute' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

@if ($tab === 'overview')
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-ink/5 p-4">
            <p class="text-mute text-xs">স্ট্যাটাস</p>
            <p class="font-medium mt-1">{{ $device->liveStatus() }}</p>
        </div>
        <div class="bg-white rounded-xl border border-ink/5 p-4">
            <p class="text-mute text-xs">শেষ দেখা</p>
            <p class="font-medium mt-1">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-ink/5 p-4">
            <p class="text-mute text-xs">ব্যাটারি</p>
            <p class="font-medium mt-1">{{ $device->battery_pct !== null ? $device->battery_pct.'%' : '—' }}{{ $device->charging ? ' ⚡' : '' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-ink/5 p-4">
            <p class="text-mute text-xs">নেটওয়ার্ক</p>
            <p class="font-medium mt-1">{{ $device->network_type ?? '—' }}</p>
        </div>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($featureStates as $feature => $state)
            @php [$aCls, $aLabel] = $activationBadge[$state->activation_status] ?? ['bg-ink/5 text-mute', $state->activation_status]; @endphp
            <span class="px-2 py-1 rounded text-xs {{ $aCls }}">{{ $featureLabels[$feature] ?? $feature }}: {{ $aLabel }}</span>
        @endforeach
    </div>
@endif

@if ($tab === 'notifications')
    @php
        $notifState = $featureStates->get('notification_monitoring');
        $notifAccess = $notifState?->android_access['notification_listener'] ?? 'not_requested';
        [$naCls, $naLabel] = $accessBadge[$notifAccess] ?? ['bg-ink/5 text-mute', $notifAccess];
        $consentLabel = match ($notifState?->app_consent_status) {
            'enabled' => 'Enabled', 'disabled' => 'Disabled', default => 'Not Asked',
        };
        [$actCls, $actLabel] = $activationBadge[$notifState?->activation_status ?? 'inactive'] ?? ['bg-ink/5 text-mute', '—'];
        $currentCategory = request('category', 'all');
        $currentRange = request('range');
    @endphp

    {{-- Status strip — Android access, app consent, and computed feature
         status are three SEPARATE facts (see docs/device-intelligence-architecture.md
         §6 "Android Access != App Consent"), never collapsed into one. --}}
    <div class="flex flex-wrap gap-2 mb-4">
        <span class="px-2 py-1 rounded text-xs {{ $naCls }}">Notification Access: {{ $naLabel }}</span>
        <span class="px-2 py-1 rounded text-xs bg-ink/5 text-mute">App Consent: {{ $consentLabel }}</span>
        <span class="px-2 py-1 rounded text-xs {{ $actCls }}">Feature: {{ $actLabel }}</span>
    </div>

    {{-- Named app quick-filter tabs --}}
    <div class="flex flex-wrap gap-1 mb-3">
        @foreach (['all' => 'All'] + $categoryLabels as $key => $label)
            <a href="{{ route('super.device-intelligence.devices.show', [$tenant, $device]) }}?{{ http_build_query(array_merge(request()->except(['page', 'category']), ['tab' => 'notifications', 'category' => $key])) }}"
               class="px-3 py-1.5 rounded-lg text-xs font-medium {{ $currentCategory === $key ? 'bg-leafdk text-white' : 'bg-ink/5 text-mute hover:bg-ink/10' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Date quick-presets + custom range/sender/search --}}
    <form class="flex flex-wrap gap-2 mb-4 items-center">
        <input type="hidden" name="tab" value="notifications">
        <input type="hidden" name="category" value="{{ $currentCategory }}">
        @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => '7 Days', '30d' => '30 Days'] as $key => $label)
            <button type="submit" name="range" value="{{ $key }}"
                class="px-3 py-1.5 rounded-lg text-xs font-medium {{ $currentRange === $key ? 'bg-ink text-white' : 'bg-ink/5 text-mute hover:bg-ink/10' }}">
                {{ $label }}
            </button>
        @endforeach
        <input name="sender" value="{{ request('sender') }}" placeholder="প্রেরক..." class="rounded-lg border border-ink/15 px-3 py-2 text-xs w-32">
        <input name="q" value="{{ request('q') }}" placeholder="খুঁজুন..." class="rounded-lg border border-ink/15 px-3 py-2 text-xs w-40">
        <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border border-ink/15 px-3 py-2 text-xs">
        <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border border-ink/15 px-3 py-2 text-xs">
        <button class="px-3 py-2 rounded-lg text-xs font-medium bg-ink/5 hover:bg-ink/10">ফিল্টার</button>
    </form>

    <div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                <th class="px-4 py-3">Time</th>
                <th class="px-4 py-3">App</th>
                <th class="px-4 py-3">Sender</th>
                <th class="px-4 py-3">Notification / Message Preview</th>
            </tr></thead>
            <tbody>
            @forelse ($notifications as $n)
                <tr class="border-b border-ink/5 last:border-0 align-top">
                    <td class="px-4 py-3 text-mute text-xs whitespace-nowrap">{{ $n->posted_at->diffForHumans() }}</td>
                    <td class="px-4 py-3 font-medium">{{ $n->app_name ?: $n->package_name }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $n->sender ?: '—' }}</td>
                    <td class="px-4 py-3 text-xs">
                        @if ($n->conversation_title)
                            <span class="text-mute">{{ $n->conversation_title }} · </span>
                        @endif
                        @if ($n->title)
                            <span class="font-medium">{{ $n->title }}</span>
                        @endif
                        @if ($n->body)
                            <span class="text-ink/80">{{ $n->title ? ' — ' : '' }}{{ Str::limit($n->body, 200) }}</span>
                        @endif
                        @if ($n->removed_at)
                            <span class="text-mute text-[10px] block mt-1">প্রত্যাহার করা হয়েছে {{ $n->removed_at->diffForHumans() }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-12 text-center text-mute">কোনো নোটিফিকেশন পাওয়া যায়নি।</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $notifications?->links() }}</div>
@endif

@if ($tab === 'usage')
    <div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                <th class="px-4 py-3">App</th>
                <th class="px-4 py-3">Today</th>
                <th class="px-4 py-3">7 Days</th>
                <th class="px-4 py-3">30 Days</th>
                <th class="px-4 py-3">Last Used</th>
            </tr></thead>
            <tbody>
            @forelse ($usage as $u)
                @php $fmt = fn ($s) => sprintf('%dh %dm', intdiv($s, 3600), intdiv($s % 3600, 60)); @endphp
                <tr class="border-b border-ink/5 last:border-0">
                    <td class="px-4 py-3 font-medium">{{ $u->app_name ?: $u->package_name }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $fmt($u->today_seconds) }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $fmt($u->seven_day_seconds) }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $fmt($u->thirty_day_seconds) }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $u->last_used_at ? \Illuminate\Support\Carbon::parse($u->last_used_at)->diffForHumans() : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-12 text-center text-mute">কোনো ব্যবহার তথ্য নেই।</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endif

@if ($tab === 'health')
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">ব্যাটারি</p><p class="font-medium mt-1">{{ $device->battery_pct !== null ? $device->battery_pct.'%' : '—' }}{{ $device->charging ? ' ⚡ চার্জ হচ্ছে' : '' }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">ব্যাটারি সেভার</p><p class="font-medium mt-1">{{ $device->battery_saver === null ? '—' : ($device->battery_saver ? 'চালু' : 'বন্ধ') }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">স্ক্রিন</p><p class="font-medium mt-1">{{ $device->screen_on === null ? '—' : ($device->screen_on ? 'চালু' : 'বন্ধ') }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">স্টোরেজ (মুক্ত / মোট)</p><p class="font-medium mt-1">{{ $bytesToMb($device->storage_free_bytes) }} / {{ $bytesToMb($device->storage_total_bytes) }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">RAM (উপলব্ধ / মোট)</p><p class="font-medium mt-1">{{ $bytesToMb($device->ram_available_bytes) }} / {{ $bytesToMb($device->ram_total_bytes) }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">নেটওয়ার্ক</p><p class="font-medium mt-1">{{ $device->network_type ?? '—' }} {{ $device->vpn_active ? '· VPN' : '' }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">আপটাইম</p><p class="font-medium mt-1">{{ $device->device_uptime_seconds !== null ? gmdate('H:i:s', $device->device_uptime_seconds) : '—' }}</p></div>
        <div class="bg-white rounded-xl border border-ink/5 p-4"><p class="text-mute text-xs">টেলিমেট্রি সিঙ্ক</p><p class="font-medium mt-1">{{ $device->telemetry_synced_at?->diffForHumans() ?? '—' }}</p></div>
    </div>
@endif

@if ($tab === 'permissions')
    <div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                <th class="px-4 py-3">ফিচার</th>
                <th class="px-4 py-3">অ্যাপ সম্মতি</th>
                <th class="px-4 py-3">অ্যান্ড্রয়েড অ্যাক্সেস</th>
                <th class="px-4 py-3">অবস্থা</th>
                <th class="px-4 py-3">সম্মতি পরিবর্তন</th>
                <th class="px-4 py-3">সর্বশেষ রিপোর্ট</th>
            </tr></thead>
            <tbody>
            @forelse ($featureStates as $feature => $state)
                @php [$aCls, $aLabel] = $activationBadge[$state->activation_status] ?? ['bg-ink/5 text-mute', $state->activation_status]; @endphp
                <tr class="border-b border-ink/5 last:border-0 align-top">
                    <td class="px-4 py-3 font-medium">{{ $featureLabels[$feature] ?? $feature }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $state->app_consent_status }}</td>
                    <td class="px-4 py-3">
                        @foreach (($state->android_access ?? []) as $key => $val)
                            @php [$c, $l] = $accessBadge[$val] ?? ['bg-ink/5 text-mute', $val]; @endphp
                            <span class="px-1.5 py-0.5 rounded text-[10px] {{ $c }} mr-1">{{ $key }}: {{ $l }}</span>
                        @endforeach
                    </td>
                    <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $aCls }}">{{ $aLabel }}</span></td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $state->consent_changed_at?->diffForHumans() ?? '—' }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $state->access_synced_at?->diffForHumans() ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-12 text-center text-mute">এখনো কোনো ফিচার সিঙ্ক হয়নি।</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="text-mute text-[11px] mt-3">Admin শুধু বর্তমান অবস্থা দেখতে পারেন — অ্যান্ড্রয়েড সিস্টেম অনুমতি দূর থেকে দেওয়ার কোনো উপায় নেই; এটি সবসময় টেনেন্টের নিজের ডিভাইসে করতে হয়।</p>
@endif

@if ($tab === 'history')
    <div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-mute"><tr class="border-b border-ink/5">
                <th class="px-4 py-3">সময়</th>
                <th class="px-4 py-3">ইভেন্ট</th>
                <th class="px-4 py-3">নোট</th>
            </tr></thead>
            <tbody>
            @forelse ($history as $e)
                <tr class="border-b border-ink/5 last:border-0">
                    <td class="px-4 py-3 text-mute text-xs">{{ $e->created_at?->diffForHumans() }}</td>
                    <td class="px-4 py-3 font-medium text-xs">{{ $e->event_type }}</td>
                    <td class="px-4 py-3 text-mute text-xs">{{ $e->note }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-4 py-12 text-center text-mute">কোনো ইতিহাস নেই।</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $history?->links() }}</div>
@endif
@endsection
