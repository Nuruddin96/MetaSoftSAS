@extends('layouts.super')
@section('title', $tenant->store_name.' — রিমোট সাপোর্ট')
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
@endphp

<a href="{{ route('super.remote-support.index') }}" class="text-mute text-sm hover:underline">← রিমোট সাপোর্ট</a>
<h1 class="font-disp font-bold text-2xl mt-1 mb-6">{{ $tenant->store_name }}</h1>

<div class="bg-white rounded-xl border border-ink/5 p-5 mb-6 flex items-center justify-between">
    <div>
        <p class="font-medium">টেনেন্ট-লেভেল রিমোট সাপোর্ট</p>
        <p class="text-mute text-xs mt-1">বন্ধ থাকলে এই টেনেন্টের অ্যাপে ডিভাইস রেজিস্ট্রেশন বা সেটআপ স্ক্রিন কিছুই দেখা যায় না।</p>
    </div>
    <form method="POST" action="{{ route('super.remote-support.toggle', $tenant) }}">
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
            <th class="px-4 py-3">স্ট্যাটাস</th>
            <th class="px-4 py-3">শেষ দেখা</th>
            <th class="px-4 py-3">ব্যাটারি</th>
            <th class="px-4 py-3"></th>
        </tr></thead>
        <tbody>
        @forelse ($devices as $d)
            @php [$cls, $label] = $statusBadge[$d->liveStatus()] ?? ['bg-ink/5 text-mute', $d->liveStatus()]; @endphp
            <tr class="border-b border-ink/5 last:border-0 align-top">
                <td class="px-4 py-3">
                    <p class="font-medium">{{ $d->device_model ?: 'অজানা মডেল' }}</p>
                    <p class="text-mute text-xs">{{ $d->user?->name }} · Android {{ $d->os_version }} · v{{ $d->app_version }}</p>
                    <p class="text-mute text-[11px] mt-1">{{ Str::limit($d->device_uuid, 16) }}</p>
                </td>
                <td class="px-4 py-3"><span class="px-2 py-1 rounded text-xs {{ $cls }}">{{ $label }}</span></td>
                <td class="px-4 py-3 text-mute text-xs">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="px-4 py-3 text-mute text-xs">{{ $d->battery_pct !== null ? $d->battery_pct.'%'.($d->charging ? ' ⚡' : '') : '—' }}</td>
                <td class="px-4 py-3 space-y-2">
                    @if ($d->status === 'pending_verification')
                        <form method="POST" action="{{ route('super.remote-support.devices.approve', [$tenant, $d]) }}" class="flex gap-2">
                            @csrf
                            <input name="verification_code" placeholder="কোড" required
                                   class="rounded-lg border border-ink/15 px-2 py-1.5 text-xs w-24">
                            <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leaf text-white">অনুমোদন</button>
                        </form>
                    @elseif ($d->status === 'revoked')
                        <span class="text-mute text-xs">{{ $d->revoke_reason }}</span>
                    @else
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('super.remote-support.devices.toggle', [$tenant, $d]) }}">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $d->remote_support_enabled ? '0' : '1' }}">
                                <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-ink/5 hover:bg-ink/10">
                                    {{ $d->remote_support_enabled ? 'ডিভাইস বন্ধ করুন' : 'ডিভাইস চালু করুন' }}
                                </button>
                            </form>
                            @if ($d->liveStatus() === 'on_ready')
                                <form method="POST" action="{{ route('super.remote-support.session.start', [$tenant, $d]) }}" class="flex items-center gap-2">
                                    @csrf
                                    <label class="text-[11px] text-mute flex items-center gap-1"><input type="checkbox" name="include_microphone" value="1"> মাইক্রোফোন</label>
                                    <label class="text-[11px] text-mute flex items-center gap-1"><input type="checkbox" name="include_camera" value="1"> ক্যামেরা</label>
                                    <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leafdk text-white">লাইভ স্ক্রিন দেখুন</button>
                                </form>
                            @endif
                        </div>
                    @endif
                    @if ($d->status !== 'revoked')
                        <form method="POST" action="{{ route('super.remote-support.devices.revoke', [$tenant, $d]) }}"
                              onsubmit="return confirm('ডিভাইসটির অনুমতি স্থায়ীভাবে প্রত্যাহার করবেন?');">
                            @csrf
                            <button class="text-red-600 hover:underline text-xs">অনুমতি প্রত্যাহার</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-12 text-center text-mute">এখনো কোনো ডিভাইস রেজিস্টার হয়নি।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
