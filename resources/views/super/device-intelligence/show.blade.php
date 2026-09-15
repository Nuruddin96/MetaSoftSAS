@extends('layouts.super')
@section('title', $tenant->store_name.' — ডিভাইস ইন্টেলিজেন্স')
@section('content')
@php
    $statusBadge = [
        'pending_verification' => ['bg-ink/5 text-mute', 'অপেক্ষমাণ যাচাই'],
        'off' => ['bg-ink/5 text-mute', 'বন্ধ'],
        'on_not_ready' => ['bg-amber/10 text-amber', 'প্রস্তুত নয়'],
        'on_ready' => ['bg-leaf/10 text-leafdk', 'প্রস্তুত'],
        'offline' => ['bg-red-50 text-red-600', 'অফলাইন'],
        'revoked' => ['bg-red-100 text-red-700', 'বাতিল'],
    ];
    $activationBadge = [
        'inactive' => ['bg-ink/5 text-mute', 'নিষ্ক্রিয়'],
        'waiting_for_android_access' => ['bg-amber/10 text-amber', 'অপেক্ষায়'],
        'disabled_by_tenant' => ['bg-red-50 text-red-600', 'বন্ধ'],
        'active' => ['bg-leaf/10 text-leafdk', 'সক্রিয়'],
    ];
    $featureLabels = [
        'notification_monitoring' => 'নোটিফিকেশন',
        'app_usage' => 'অ্যাপ ব্যবহার',
        'device_health' => 'ডিভাইস স্বাস্থ্য',
        'location' => 'লোকেশন',
    ];
@endphp

<a href="{{ route('super.device-intelligence.index') }}" class="text-mute text-sm hover:underline">← ডিভাইস ইন্টেলিজেন্স</a>
<h1 class="font-disp font-bold text-2xl mt-1 mb-6">{{ $tenant->store_name }}</h1>

<div class="bg-white rounded-xl border border-ink/5 p-5 mb-6 flex items-center justify-between">
    <div>
        <p class="font-medium">টেনেন্ট-লেভেল ডিভাইস ইন্টেলিজেন্স</p>
        <p class="text-mute text-xs mt-1">বন্ধ থাকলে এই টেনেন্টের অ্যাপে ডিভাইস ইন্টেলিজেন্স মডিউল দেখা যায় না — Remote Support থেকে সম্পূর্ণ আলাদা।</p>
    </div>
    <form method="POST" action="{{ route('super.device-intelligence.toggle', $tenant) }}">
        @csrf
        <input type="hidden" name="enabled" value="{{ $setting?->enabled ? '0' : '1' }}">
        <button class="px-4 py-2 rounded-lg text-sm font-medium {{ $setting?->enabled ? 'bg-red-50 text-red-600' : 'bg-leaf text-white' }}">
            {{ $setting?->enabled ? 'বন্ধ করুন' : 'চালু করুন' }}
        </button>
    </form>
</div>

<h2 class="font-medium mb-3">ডিভাইসসমূহ</h2>
<div class="bg-white rounded-xl border border-ink/5 overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-left text-mute"><tr class="border-b border-ink/5">
            <th class="px-4 py-3">ডিভাইস</th>
            <th class="px-4 py-3">অনলাইন স্ট্যাটাস</th>
            <th class="px-4 py-3">ব্যাটারি</th>
            <th class="px-4 py-3">স্ক্রিন</th>
            <th class="px-4 py-3">মনিটরিং স্ট্যাটাস</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody>
        @forelse ($devices as $d)
            @php
                [$cls, $label] = $statusBadge[$d->liveStatus()] ?? ['bg-ink/5 text-mute', $d->liveStatus()];
                $states = $featureStates->get($d->id, collect())->keyBy('feature');
            @endphp
            <tr class="border-b border-ink/5 last:border-0 align-top">
                <td class="px-4 py-3">
                    <p class="font-medium">{{ $d->device_model ?: 'অজানা মডেল' }}</p>
                    <p class="text-mute text-xs">{{ $d->user?->name }} · Android {{ $d->os_version }} · v{{ $d->app_version }}</p>
                </td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $cls }}">{{ $label }}</span></td>
                <td class="px-4 py-3 text-mute text-xs">{{ $d->battery_pct !== null ? $d->battery_pct.'%'.($d->charging ? ' ⚡' : '') : '—' }}</td>
                <td class="px-4 py-3 text-mute text-xs">
                    @if ($d->screen_on === null) — @else {{ $d->screen_on ? 'চালু' : 'বন্ধ' }} @endif
                </td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        @foreach (['notification_monitoring', 'app_usage'] as $feature)
                            @php
                                $status = $states->get($feature)?->activation_status ?? 'inactive';
                                [$aCls, $aLabel] = $activationBadge[$status] ?? ['bg-ink/5 text-mute', $status];
                            @endphp
                            <span class="px-1.5 py-0.5 rounded text-[10px] {{ $aCls }}">{{ $featureLabels[$feature] }}: {{ $aLabel }}</span>
                        @endforeach
                    </div>
                </td>
                <td class="px-4 py-3">
                    <a href="{{ route('super.device-intelligence.devices.show', [$tenant, $d]) }}" class="text-leafdk hover:underline text-xs">বিস্তারিত দেখুন →</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-12 text-center text-mute">এখনো কোনো ডিভাইস রেজিস্টার হয়নি।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
