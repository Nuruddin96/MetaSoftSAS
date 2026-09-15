@extends('layouts.super')
@section('title', 'লাইভ স্ক্রিন — '.$tenant->store_name)
@section('content')
@php
    $micPermitted = (bool) ($device->permissions['microphone'] ?? false);
    $cameraPermitted = (bool) ($device->permissions['camera'] ?? false);
@endphp

<a href="{{ route('super.remote-support.show', $tenant) }}" class="text-mute text-sm hover:underline">← {{ $tenant->store_name }}</a>
<h1 class="font-disp font-bold text-2xl mt-1 mb-1">{{ $tenant->store_name }} — {{ $device->device_model ?: $device->device_uuid }}</h1>
<p class="text-mute text-xs mb-4">{{ $device->user?->name }} · Android {{ $device->os_version }}</p>

<div class="flex flex-wrap items-center gap-3 mb-4 text-sm">
    <span id="connStatus" class="px-3 py-1.5 rounded-full bg-ink/5 text-mute font-medium">সংযোগ হচ্ছে…</span>
    <button id="reconnectBtn" class="ml-auto px-4 py-2 rounded-lg text-sm font-medium bg-ink/5 text-ink hover:bg-ink/10 disabled:opacity-50 disabled:cursor-not-allowed">🔄 রিকানেক্ট</button>
    <button id="stopBtn" class="px-4 py-2 rounded-lg text-sm font-medium bg-red-50 text-red-600">সেশন বন্ধ করুন</button>
</div>

{{--
    Bounded-height box + `object-contain` on the <video> itself (not a
    fixed-aspect-ratio box): confirmed live (2026-08-21) that a plain
    `aspect-video`/16:9 box was the wrong fix — it forces every stream,
    portrait phones included, into a landscape-shaped frame, so a real
    1080x2400 portrait capture only occupies a thin pillarboxed sliver in
    the middle of a wide black box. The <video> element is a *replaced
    element*: giving it `max-width/max-height` bounds (instead of forcing
    `w-full h-full`) plus `object-contain` lets the browser size it from
    its own real intrinsic videoWidth/videoHeight — the largest size that
    fits both the available width AND the available height — which is
    exactly "fit the whole phone screen, preserve its real aspect ratio, no
    stretch/crop, no scrolling" for ANY device orientation, not just 16:9,
    with no JS needed to read/track the stream's real dimensions. The outer
    box only supplies the available area: full width, and a height capped
    well inside the viewport (`clamp(...)`) so the whole frame — whatever
    its real shape — is always visible without scrolling to reach it,
    exactly the earlier bug (box rendering at native ~3500px height,
    confirmed via video.videoWidth/videoHeight matching the real device and
    connectionState 'connected' — the stream itself was never broken, only
    this container's sizing).
--}}
<div class="bg-black rounded-xl overflow-hidden relative flex items-center justify-center mx-auto"
     style="width: 100%; height: clamp(240px, calc(100vh - 320px), 900px);">
    <video id="remoteVideo" autoplay playsinline class="max-w-full max-h-full object-contain"></video>
    <p id="waitingNote" class="text-white/50 text-sm absolute">ডিভাইসের স্ক্রিন ক্যাপচার অনুমতির জন্য অপেক্ষা করা হচ্ছে…</p>
    <video id="cameraVideo" autoplay playsinline muted
           class="hidden absolute bottom-3 right-3 w-32 h-24 rounded-lg border-2 border-white/40 object-cover bg-black"></video>
    <audio id="remoteAudio" autoplay class="hidden"></audio>
</div>

{{-- Independent Remote Support capabilities — see
     docs/remote-support-architecture.md §Independent capabilities. Every
     tile reflects an ACTUAL WebRTC/capability-status signal, never a
     UI-only toggle. Whichever capability(ies) this session was created
     with (see show.blade.php) starts negotiating immediately; the other
     three get their own Start button here, sending a `capability-start`
     signal the device answers by adding that ONE capability's track(s)
     to the SAME already-connected PeerConnection — never a new session,
     never re-asking for already-granted Android access. --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
    <div class="bg-white rounded-xl border border-ink/5 p-4" data-capability-tile="screen">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-mute">🎥 Screen</p>
            <button data-toggle-btn="screen" class="hidden text-[11px] px-2 py-1 rounded bg-ink/5 hover:bg-ink/10"></button>
        </div>
        <p data-state="screen" class="font-medium text-sm">{{ $session->include_screen ? 'সংযোগ হচ্ছে…' : 'বন্ধ' }}</p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-4" data-capability-tile="camera">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-mute">📷 Camera</p>
            <button data-toggle-btn="camera" class="hidden text-[11px] px-2 py-1 rounded bg-ink/5 hover:bg-ink/10"></button>
        </div>
        <p data-state="camera" class="font-medium text-sm">{{ $session->include_camera ? 'সংযোগ হচ্ছে…' : 'বন্ধ' }}</p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-4" data-capability-tile="microphone">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-mute">🎙 Microphone</p>
            <button data-toggle-btn="microphone" class="hidden text-[11px] px-2 py-1 rounded bg-ink/5 hover:bg-ink/10"></button>
        </div>
        <p data-state="microphone" class="font-medium text-sm">{{ $session->include_microphone ? 'সংযোগ হচ্ছে…' : 'বন্ধ' }}</p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-4" data-capability-tile="device_audio">
        <div class="flex items-center justify-between mb-1">
            <p class="text-xs text-mute">🔊 Device Audio</p>
            <button data-toggle-btn="device_audio" class="hidden text-[11px] px-2 py-1 rounded bg-ink/5 hover:bg-ink/10"></button>
        </div>
        <p data-state="device_audio" class="font-medium text-sm">{{ $session->include_device_audio ? 'সংযোগ হচ্ছে…' : 'বন্ধ' }}</p>
    </div>
</div>

<p class="text-mute text-xs mt-3">
    ডিভাইসে Android-এর নিজস্ব সিস্টেম সম্মতি ডায়ালগ ও রেকর্ডিং/মাইক্রোফোন ইন্ডিকেটর দেখানো বাধ্যতামূলক — এটি এড়িয়ে যাওয়া যায় না
    (দেখুন docs/permission-flow.md)। ডিভাইসের ব্যবহারকারী অনুমতি না দিলে কোনো ট্র্যাক কখনো শুরু হবে না।
</p>

@php
    $signalSendUrl = route('super.remote-support.session.signal.send', [$tenant, $device, $session]);
    $signalPollUrl = route('super.remote-support.session.signal.poll', [$tenant, $device, $session]);
    $stopUrl = route('super.remote-support.session.stop', [$tenant, $device, $session]);
    $initialCapabilitiesJson = [
        'screen' => (bool) $session->include_screen,
        'camera' => (bool) $session->include_camera,
        'microphone' => (bool) $session->include_microphone,
        'device_audio' => (bool) $session->include_device_audio,
    ];
@endphp

<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        ?? '{{ csrf_token() }}';
    const sendUrl = @json($signalSendUrl);
    const pollUrl = @json($signalPollUrl);
    const stopUrl = @json($stopUrl);
    const iceServers = @json($iceServers);
    const initialCapabilities = @json($initialCapabilitiesJson);

    const video = document.getElementById('remoteVideo');
    const cameraVideo = document.getElementById('cameraVideo');
    const remoteAudio = document.getElementById('remoteAudio');
    const waitingNote = document.getElementById('waitingNote');
    const connStatus = document.getElementById('connStatus');
    const stopBtn = document.getElementById('stopBtn');
    const reconnectBtn = document.getElementById('reconnectBtn');

    const CAPABILITY_LABELS = { screen: '🎥 Screen', camera: '📷 Camera', microphone: '🎙 Microphone', device_audio: '🔊 Device Audio' };
    const STATE_LABELS_BN = {
        off: 'বন্ধ', starting: 'সংযোগ হচ্ছে…', active: 'স্ট্রিমিং হচ্ছে ✅',
        unavailable: 'অনুপলব্ধ', stopped: 'বন্ধ করা হয়েছে', error: 'ত্রুটি',
    };

    let since = 0;
    let polling = true;
    let pc = null;

    /**
     * FIFO correlation, not positional/ordinal guessing (the FRAGILE
     * heuristic this replaces assumed screen-then-mic-then-camera track
     * order, which four independently-toggleable capabilities breaks) —
     * WebRtcSessionController.dart always sends a
     * `capability-status: starting` signal BEFORE it ever adds that
     * capability's track(s), and signals are polled/processed strictly in
     * id order (same as every other signal type here), so the front of
     * each kind-specific queue reliably names the very next `ontrack`
     * event's capability.
     */
    const pendingVideoCapabilities = [];
    const pendingAudioCapabilities = [];
    let secondVideoTileAssigned = false; // screen already owns the main <video>; the next video track is camera's own tile.

    function setStatus(text, cls) {
        connStatus.textContent = text;
        connStatus.className = 'px-3 py-1.5 rounded-full font-medium ' + cls;
    }

    function tileEls(capability) {
        return {
            state: document.querySelector(`[data-state="${capability}"]`),
            button: document.querySelector(`[data-toggle-btn="${capability}"]`),
        };
    }

    function setCapabilityState(capability, state) {
        const { state: stateEl } = tileEls(capability);
        if (stateEl) stateEl.textContent = STATE_LABELS_BN[state] ?? state;
    }

    /** The Start/Stop button for a capability NOT part of the initial set — toggles via capability-start/capability-stop signals on the SAME live session, never a new one. */
    function wireCapabilityToggle(capability) {
        const { button } = tileEls(capability);
        if (!button) return;
        let active = false;
        const render = () => {
            button.classList.remove('hidden');
            button.textContent = active ? 'বন্ধ করুন' : 'চালু করুন';
        };
        button.onclick = async () => {
            active = !active;
            render();
            await postSignal(active ? 'capability-start' : 'capability-stop', capability);
        };
        render();
    }

    /** Local-playback-only mute for an already-received track — mirrors the original mic/camera toggle exactly (see the page's own note: stopping the device's actual capture needs a real capability-stop signal, not just muting local playback). */
    function wireLocalMuteToggle(capability, mediaElement, onLabel, offLabel) {
        const { button } = tileEls(capability);
        if (!button) return;
        button.classList.remove('hidden');
        button.textContent = onLabel;
        button.onclick = () => {
            const track = mediaElement.srcObject?.getTracks()?.[0];
            if (!track) return;
            track.enabled = !track.enabled;
            button.textContent = track.enabled ? onLabel : offLabel;
        };
    }

    /** Never leave a stale "স্ট্রিমিং হচ্ছে" badge once the connection actually ends. */
    function markAllTracksStopped() {
        for (const capability of Object.keys(CAPABILITY_LABELS)) {
            const { state } = tileEls(capability);
            if (state && state.textContent === STATE_LABELS_BN.active) {
                setCapabilityState(capability, 'stopped');
            }
        }
    }

    /**
     * The device is the offering side (it owns the media). This admin
     * viewer is purely the answering side: it only ever creates an
     * RTCPeerConnection once an 'offer' signal actually arrives, so
     * opening this page never itself triggers any capture on the device.
     */
    function ensurePeerConnection() {
        if (pc) return pc;

        pc = new RTCPeerConnection({ iceServers });

        pc.onicecandidate = (event) => {
            if (event.candidate) {
                postSignal('ice-candidate', JSON.stringify(event.candidate));
            }
        };

        pc.ontrack = (event) => {
            const track = event.track;

            if (track.kind === 'video') {
                const capability = pendingVideoCapabilities.shift() ?? (secondVideoTileAssigned ? 'camera' : 'screen');
                if (capability === 'screen' && !secondVideoTileAssigned) {
                    video.srcObject = event.streams[0];
                    waitingNote.style.display = 'none';
                    secondVideoTileAssigned = true;
                } else {
                    cameraVideo.srcObject = event.streams[0];
                    cameraVideo.classList.remove('hidden');
                    wireLocalMuteToggle('camera', cameraVideo, 'দেখা বন্ধ করুন', 'আবার দেখুন');
                }
                setCapabilityState(capability, 'active');
            } else if (track.kind === 'audio') {
                const capability = pendingAudioCapabilities.shift() ?? 'microphone';
                remoteAudio.srcObject = event.streams[0];
                setCapabilityState(capability, 'active');
                wireLocalMuteToggle(capability, remoteAudio, 'শোনা বন্ধ করুন', 'আবার শুনুন');
            }
        };

        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'connected') {
                setStatus('সংযুক্ত', 'bg-leaf/10 text-leafdk');
                reconnectBtn.disabled = false;
                // `ontrack` does not reliably re-fire for a track that
                // simply survives a renegotiation — restore any badge
                // whose element already has a track playing.
                if (video.srcObject) setCapabilityState('screen', 'active');
                if (remoteAudio.srcObject) setCapabilityState('microphone', 'active');
                if (cameraVideo.srcObject) setCapabilityState('camera', 'active');
            } else if (pc.connectionState === 'disconnected' || pc.connectionState === 'failed') {
                setStatus('সংযোগ বিচ্ছিন্ন — পুনঃসংযোগের চেষ্টা হচ্ছে', 'bg-amber/10 text-amber');
            } else if (pc.connectionState === 'closed') {
                setStatus('বন্ধ', 'bg-ink/5 text-mute');
                markAllTracksStopped();
            }
        };

        return pc;
    }

    async function postSignal(type, payload) {
        await fetch(sendUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({ type, payload }),
        });
    }

    async function handleSignal(signal, skip = false) {
        const conn = ensurePeerConnection();

        if (signal.type === 'offer') {
            if (skip) return;
            await conn.setRemoteDescription(JSON.parse(signal.payload));
            const answer = await conn.createAnswer();
            await conn.setLocalDescription(answer);
            await postSignal('answer', JSON.stringify(answer));
        } else if (signal.type === 'ice-candidate') {
            try {
                await conn.addIceCandidate(JSON.parse(signal.payload));
            } catch (e) {
                console.warn('ICE candidate add failed', e);
            }
        } else if (signal.type === 'bye') {
            polling = false;
            setStatus('ডিভাইস সংযোগ শেষ করেছে', 'bg-ink/5 text-mute');
            markAllTracksStopped();
            pc?.close();
        } else if (signal.type === 'capability-status') {
            let data;
            try {
                data = JSON.parse(signal.payload);
            } catch (e) {
                return;
            }
            const capability = data.capability;
            const state = data.state;
            if (!(capability in CAPABILITY_LABELS)) return;
            if (state === 'starting') {
                if (capability === 'screen' || capability === 'camera') pendingVideoCapabilities.push(capability);
                if (capability === 'microphone' || capability === 'device_audio') pendingAudioCapabilities.push(capability);
            }
            // 'active' is set by ontrack itself once the track actually
            // arrives (more trustworthy than the device's own optimistic
            // report) — every OTHER state (unavailable/stopped/error/off)
            // is exactly what the device reported, shown as-is.
            if (state !== 'active') {
                setCapabilityState(capability, state);
            }
        }
    }

    async function pollLoop() {
        while (polling) {
            try {
                const res = await fetch(pollUrl + '?since=' + since, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    // A page reload (or reopening this tab mid-session)
                    // starts `since` back at 0 — only the LATEST offer in
                    // a re-fetched batch is still the device's actual
                    // current state; a stale earlier one (superseded by a
                    // later renegotiation already in this same batch)
                    // must never be re-answered.
                    const latestOfferId = data.signals.reduce(
                        (max, s) => (s.type === 'offer' ? Math.max(max, s.id) : max),
                        -1,
                    );
                    for (const signal of data.signals) {
                        since = Math.max(since, signal.id);
                        await handleSignal(signal, signal.type === 'offer' && signal.id !== latestOfferId);
                    }
                    if (data.session_status === 'ended') {
                        polling = false;
                        setStatus('সেশন শেষ হয়েছে', 'bg-ink/5 text-mute');
                        markAllTracksStopped();
                        pc?.close();
                    }
                }
            } catch (e) {
                console.warn('signal poll failed', e);
            }
            await new Promise((r) => setTimeout(r, 1500));
        }
    }

    reconnectBtn.addEventListener('click', async () => {
        reconnectBtn.disabled = true;
        setStatus('সংযোগ বিচ্ছিন্ন — পুনঃসংযোগের চেষ্টা হচ্ছে', 'bg-amber/10 text-amber');
        try {
            await postSignal('reconnect-request', '');
        } catch (e) {
            console.warn('reconnect request failed to send', e);
        }
        setTimeout(() => { reconnectBtn.disabled = false; }, 5000);
    });

    stopBtn.addEventListener('click', async () => {
        polling = false;
        pc?.close();
        await fetch(stopUrl, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
        });
        window.location = @json(route('super.remote-support.show', $tenant));
    });

    // Every capability NOT part of the initial set gets its own Start
    // toggle immediately (never waits for a track — there may never be
    // one until the admin actually asks for it).
    for (const capability of Object.keys(CAPABILITY_LABELS)) {
        if (!initialCapabilities[capability]) wireCapabilityToggle(capability);
    }

    pollLoop();
})();
</script>
@endsection
