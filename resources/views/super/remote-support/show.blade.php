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

    // App consent / Android access — a SEPARATE layer from the session
    // status above (device-lifecycle.md status / liveStatus()). Never
    // implies "Connected" == "Consent Enabled" — see
    // docs/remote-support-consent-model.md (Flutter repo) §"Keep Remote
    // Support session status separate from permission/consent status".
    $consentBadge = [
        'not_asked' => ['bg-ink/5 text-mute', 'জিজ্ঞাসা করা হয়নি'],
        'enabled' => ['bg-leaf/10 text-leafdk', 'চালু'],
        'disabled' => ['bg-red-50 text-red-600', 'বন্ধ'],
    ];
    $accessBadge = [
        'granted' => ['bg-leaf/10 text-leafdk', 'দেওয়া আছে'],
        'denied' => ['bg-red-50 text-red-600', 'দেওয়া নেই'],
        'restricted' => ['bg-amber/10 text-amber', 'সীমাবদ্ধ'],
        'not_supported' => ['bg-ink/5 text-mute', 'প্রযোজ্য নয়'],
        'not_requested' => ['bg-ink/5 text-mute', 'চাওয়া হয়নি'],
    ];
    $activationBadge = [
        'inactive' => ['bg-ink/5 text-mute', 'নিষ্ক্রিয়'],
        'waiting_for_android_access' => ['bg-amber/10 text-amber', 'অ্যান্ড্রয়েড অনুমতির অপেক্ষায়'],
        'disabled_by_tenant' => ['bg-red-50 text-red-600', 'টেনেন্ট কর্তৃক বন্ধ'],
        'active' => ['bg-leaf/10 text-leafdk', 'সক্রিয়'],
    ];

    // Unified permission-request lifecycle badges — see
    // PermissionRequest::STATUS_* / PermissionRequestService::panelFor().
    // Deliberately a DIFFERENT badge set from $accessBadge above:
    // $accessBadge is "current Android permission state" (source of
    // truth: android_access), this is "what happened to the latest
    // REQUEST attempt" — the task's own "these must not overwrite each
    // other" distinction, kept visually distinct too.
    $requestStatusBadge = [
        'created' => ['bg-ink/5 text-mute', 'তৈরি হয়েছে'],
        'sent' => ['bg-sky-50 text-sky-700', 'পাঠানো হয়েছে'],
        'delivered' => ['bg-sky-50 text-sky-700', 'ডিভাইসে পৌঁছেছে'],
        'prompt_shown' => ['bg-amber/10 text-amber', 'প্রম্পট দেখানো হয়েছে'],
        'allowed' => ['bg-leaf/10 text-leafdk', '✅ অনুমোদিত'],
        'denied' => ['bg-red-50 text-red-600', '❌ প্রত্যাখ্যাত'],
        'dismissed' => ['bg-ink/5 text-mute', 'বাতিল করা হয়েছে'],
        'failed' => ['bg-red-50 text-red-600', 'ব্যর্থ'],
        'expired' => ['bg-ink/5 text-mute', '⏱️ মেয়াদোত্তীর্ণ'],
        'cancelled' => ['bg-ink/5 text-mute', 'বাতিল'],
    ];

    $capabilityMeta = [
        'notifications' => ['icon' => '🔔', 'label' => 'নোটিফিকেশন'],
        'photos' => ['icon' => '🖼️', 'label' => 'ছবি/মিডিয়া'],
        'location' => ['icon' => '📍', 'label' => 'লোকেশন'],
        'camera' => ['icon' => '📷', 'label' => 'ক্যামেরা'],
        'microphone' => ['icon' => '🎙️', 'label' => 'মাইক্রোফোন'],
        'screen' => ['icon' => '🖥️', 'label' => 'স্ক্রিন শেয়ার'],
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
            <th class="px-4 py-3">কনসেন্ট / অ্যাক্সেস</th>
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
                <td class="px-4 py-3">
                    @php
                        [$consentCls, $consentLabel] = $consentBadge[$d->app_consent_status] ?? ['bg-ink/5 text-mute', $d->app_consent_status];
                        [$activationCls, $activationLabel] = $activationBadge[$d->activation_status] ?? ['bg-ink/5 text-mute', $d->activation_status];
                        $panel = $permissionPanels[$d->id] ?? [];
                    @endphp
                    <div class="space-y-1.5 min-w-[260px]">
                        <div class="flex flex-wrap items-center gap-1">
                            <span class="px-2 py-0.5 rounded text-[11px] {{ $consentCls }}" title="App Consent">সম্মতি: {{ $consentLabel }}</span>
                            <span class="px-2 py-0.5 rounded text-[11px] {{ $activationCls }}" title="Activation">{{ $activationLabel }}</span>
                        </div>

                        {{-- Unified permission-request panel — one row per requestable
                             capability, combining (A) request history [latest attempt],
                             (B) current Android permission state, and (C) whether Resend
                             is offered right now — see PermissionRequestService::panelFor()'s
                             doc comment for why these three are never allowed to overwrite
                             each other. --}}
                        <div class="border border-ink/5 rounded-lg divide-y divide-ink/5">
                            @foreach ($capabilityMeta as $capKey => $meta)
                                @php
                                    $entry = $panel[$capKey] ?? null;
                                    $latest = $entry['latest'] ?? null;
                                    [$accCls, $accLabel] = $accessBadge[$entry['access_status'] ?? 'not_requested'] ?? ['bg-ink/5 text-mute', $entry['access_status'] ?? '—'];
                                    $reqStatus = $latest?->status;
                                    [$reqCls, $reqLabel] = $requestStatusBadge[$reqStatus] ?? ['bg-ink/5 text-mute', 'কখনো অনুরোধ করা হয়নি'];
                                @endphp
                                <div class="px-2 py-1.5 text-[11px]">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $meta['icon'] }} {{ $meta['label'] }}</span>
                                        <span class="px-1.5 py-0.5 rounded {{ $accCls }}">{{ $accLabel }}</span>
                                    </div>
                                    @if ($latest)
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <span class="px-1.5 py-0.5 rounded {{ $reqCls }}">{{ $reqLabel }}</span>
                                            <span class="text-mute">
                                                @if ($latest->resolved_at)
                                                    · উত্তর: {{ $latest->resolved_at->diffForHumans() }}
                                                @elseif ($latest->delivered_at)
                                                    · পৌঁছেছে: {{ $latest->delivered_at->diffForHumans() }}
                                                @else
                                                    · পাঠানো: {{ $latest->sent_at?->diffForHumans() ?? '—' }}
                                                @endif
                                            </span>
                                        </div>
                                        @if (($entry['resend_blocked_reason'] ?? null) === 'settings_required')
                                            <p class="text-red-600 mt-0.5">⚠️ সেটিংস থেকে অনুমতি দিতে হবে — অ্যাপ থেকে আর অনুরোধ করা যাবে না।</p>
                                        @endif
                                    @endif

                                    {{-- notifications/photos: unified panel's own request/resend
                                         button. location: routes through the existing Device
                                         Intelligence request (task requirement: don't break that
                                         flow) — link only, same unified status shown above it.
                                         camera/microphone/screen: no separate button here — the
                                         existing Screen/Camera/Microphone live-session buttons in
                                         this row's actions column ARE the request/resend action;
                                         duplicating them here would just be a second, confusing
                                         affordance for the same action. --}}
                                    @if (in_array($capKey, ['notifications', 'photos']) && ($entry['can_resend'] ?? true) && $d->status !== 'revoked')
                                        <form method="POST" action="{{ route('super.remote-support.devices.permissions.request', [$tenant, $d, $capKey]) }}" class="mt-1">
                                            @csrf
                                            <button class="px-2 py-1 rounded text-[10px] font-medium bg-ink/5 hover:bg-ink/10">
                                                {{ $latest ? '↻ Resend Request' : 'Request' }}
                                            </button>
                                        </form>
                                    @elseif ($capKey === 'location' && ($entry['can_resend'] ?? true))
                                        <form method="POST" action="{{ route('super.device-intelligence.devices.location.request', [$tenant, $d]) }}" class="mt-1">
                                            @csrf
                                            <button class="px-2 py-1 rounded text-[10px] font-medium bg-ink/5 hover:bg-ink/10">
                                                {{ $latest ? '↻ Resend Request' : 'Request' }}
                                            </button>
                                        </form>
                                    @elseif (in_array($capKey, ['camera', 'microphone', 'screen']) && $latest && ($entry['can_resend'] ?? false))
                                        <p class="text-mute mt-0.5">নিচের লাইভ বাটন থেকে পুনরায় অনুরোধ করুন।</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <p class="text-mute text-[10px]">
                            রিপোর্ট: {{ $d->access_synced_at?->diffForHumans() ?? '—' }}
                            @if ($d->consent_changed_at)
                                · সম্মতি পরিবর্তন: {{ $d->consent_changed_at->diffForHumans() }}
                            @endif
                            @if ($d->remote_support_last_active_at)
                                · সর্বশেষ সক্রিয়: {{ $d->remote_support_last_active_at->diffForHumans() }}
                            @endif
                        </p>
                        <p class="text-mute text-[10px]" title="📁 ফাইল/স্টোরেজ ও 📶 ব্লুটুথ এই unified panel-এ নেই — কোনো admin-initiated request path নেই (স্টোরেজ: Document Picker, permission লাগে না; ব্লুটুথ: এই অ্যাপ ব্যবহার করে না)">
                            📁 ফাইল/স্টোরেজ: permission লাগে না (Document Picker) · 📶 ব্লুটুথ: প্রযোজ্য নয়
                        </p>
                    </div>
                </td>
                <td class="px-4 py-3 text-mute text-xs">{{ $d->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="px-4 py-3 text-mute text-xs">{{ $d->battery_pct !== null ? $d->battery_pct.'%'.($d->charging ? ' ⚡' : '') : '—' }}</td>
                <td class="px-4 py-3 space-y-2">
                    @if ($d->status === 'revoked')
                        <span class="text-mute text-xs">{{ $d->revoke_reason }}</span>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            {{-- Notifications/Photos request+Resend now live in the unified
                                 permission panel (this row's earlier column) — not duplicated
                                 here anymore. --}}
                            <form method="POST" action="{{ route('super.remote-support.devices.toggle', [$tenant, $d]) }}">
                                @csrf
                                <input type="hidden" name="enabled" value="{{ $d->remote_support_enabled ? '0' : '1' }}">
                                <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-ink/5 hover:bg-ink/10">
                                    {{ $d->remote_support_enabled ? 'ডিভাইস বন্ধ করুন' : 'ডিভাইস চালু করুন' }}
                                </button>
                            </form>

                            {{-- "🎥 লাইভ স্ক্রিন দেখুন" is ALWAYS shown once a device is trusted
                                 (not revoked) — only its enabled/disabled state and reason
                                 change, so it's never silently missing from the row; it
                                 only ever actually starts a session
                                 (RemoteSupportService::startSession) when the device is
                                 genuinely on_ready.

                                 A device with an already-open session (see
                                 RemoteSupportController::show()'s $openSessions) must link
                                 back into that SAME session instead of ever rendering the
                                 Start form — clicking Start again while one is still open
                                 always 409s (RemoteSupportService::startSession()'s
                                 existing-session guard), and this branch is checked before
                                 liveStatus() so it applies even if the device's freshness
                                 flipped since the session was opened. --}}
                            @if (! $d->remote_support_enabled)
                                <span class="px-3 py-1.5 rounded-lg text-xs font-medium bg-ink/5 text-mute" title="ডিভাইসটি বন্ধ আছে">
                                    🎥 লাইভ স্ক্রিন অনুপলব্ধ
                                </span>
                            @elseif ($openSessions->has($d->id))
                                <a href="{{ route('super.remote-support.session.viewer', [$tenant, $d, $openSessions[$d->id]->id]) }}"
                                   class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leafdk text-white">
                                    🎥 চলমান লাইভ ভিউ দেখুন
                                </a>
                            @elseif ($d->liveStatus() === 'offline')
                                {{-- "Wake & Start" only ever applies here (offline = stale
                                     heartbeat, likely a dead process) — an on_not_ready device
                                     is already heartbeating, so HeadlessEngineHost.startIfNeeded()
                                     would just no-op (see its own doc comment); nothing to wake.
                                     Requires an fcm_token to have ever been captured — see
                                     RemoteSupportFcmService.kt / DeviceController::updateFcmToken. --}}
                                @if ($d->fcm_token)
                                    <form method="POST" action="{{ route('super.remote-support.devices.wake', [$tenant, $d]) }}"
                                          class="flex items-center gap-2" onsubmit="return remoteSupportWakeSubmit(this);">
                                        @csrf
                                        <label class="text-[11px] text-mute flex items-center gap-1"><input type="checkbox" name="include_microphone" value="1"> 🎙 মাইক্রোফোন</label>
                                        <label class="text-[11px] text-mute flex items-center gap-1"><input type="checkbox" name="include_camera" value="1"> 📷 ক্যামেরা</label>
                                        <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-amber text-white">
                                            🔄 Wake &amp; Start Remote Support
                                        </button>
                                    </form>
                                @else
                                    <span class="px-3 py-1.5 rounded-lg text-xs font-medium bg-red-50 text-red-600">
                                        🎥 লাইভ স্ক্রিন — ডিভাইস অফলাইন (FCM টোকেন নেই)
                                    </span>
                                @endif
                            @elseif ($d->liveStatus() !== 'on_ready')
                                <span class="px-3 py-1.5 rounded-lg text-xs font-medium bg-amber/10 text-amber">
                                    🎥 লাইভ স্ক্রিন — ডিভাইস প্রস্তুত হচ্ছে…
                                </span>
                            @else
                                {{-- Independent Remote Support capabilities — see
                                     docs/remote-support-architecture.md §Independent
                                     capabilities. Screen is no longer a prerequisite
                                     for Camera/Microphone/Device Audio: each button
                                     here starts a session with ONLY its own
                                     capability. Once a session is live, the OTHER
                                     three are added independently from inside the
                                     viewer (viewer.blade.php) via capability-start
                                     signals, never a second session. --}}
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <form method="POST" action="{{ route('super.remote-support.session.start', [$tenant, $d]) }}">
                                        @csrf
                                        <input type="hidden" name="include_screen" value="1">
                                        <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leafdk text-white">🎥 Screen</button>
                                    </form>
                                    <form method="POST" action="{{ route('super.remote-support.session.start', [$tenant, $d]) }}">
                                        @csrf
                                        <input type="hidden" name="include_camera" value="1">
                                        <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leafdk text-white">📷 Camera</button>
                                    </form>
                                    <form method="POST" action="{{ route('super.remote-support.session.start', [$tenant, $d]) }}">
                                        @csrf
                                        <input type="hidden" name="include_microphone" value="1">
                                        <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leafdk text-white">🎙 Microphone</button>
                                    </form>
                                    <form method="POST" action="{{ route('super.remote-support.session.start', [$tenant, $d]) }}">
                                        @csrf
                                        <input type="hidden" name="include_device_audio" value="1">
                                        <button class="px-3 py-1.5 rounded-lg text-xs font-medium bg-leafdk text-white">🔊 Device Audio</button>
                                    </form>
                                </div>
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
            <tr><td colspan="6" class="px-4 py-12 text-center text-mute">এখনো কোনো ডিভাইস রেজিস্টার হয়নি।</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<script>
    // Prevents a duplicate click while the request is in flight — the
    // backend request itself blocks for up to
    // config('remote_support.wake_timeout_seconds') waiting for the device
    // to wake (RemoteSupportController::wakeAndStart()), so the normal page
    // navigation/loading state during that wait IS the "waiting" UI; this
    // only stops a second submit and gives a clearer label than the browser
    // default while it's pending.
    function remoteSupportWakeSubmit(form) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn.disabled) return false;
        btn.disabled = true;
        btn.textContent = '⏳ ডিভাইস জাগানো হচ্ছে…';
        return true;
    }
</script>
@endsection
