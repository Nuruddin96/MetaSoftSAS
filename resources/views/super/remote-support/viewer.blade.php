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

    Camera used to live INSIDE this same box as a `w-32 h-24` absolutely
    positioned corner overlay — confirmed live (2026-09-21, session 225)
    that this makes an active camera nearly impossible to actually see
    next to the screen. It's now its own sibling box, hidden until camera
    is actually present, toggled between the SAME "solo, fills the row"
    shape as this box (`w-full`, no forced aspect) and a "paired with
    screen" shape (`aspect-[9/16]`, width derived from the shared height)
    — see updateViewerLayout() below, which is the only thing that ever
    changes either box's classes/style. Screen-only behavior (this box's
    own classes/style below) is never touched by that toggle.
--}}
<div id="viewersWrap" class="flex flex-col lg:flex-row gap-3 justify-center">
    <div id="screenViewerBox" class="w-full bg-black rounded-xl overflow-hidden relative flex items-center justify-center mx-auto"
         style="height: clamp(240px, calc(100vh - 320px), 900px);">
        <video id="remoteVideo" autoplay playsinline class="max-w-full max-h-full object-contain"></video>
        <p id="waitingNote" class="text-white/50 text-sm absolute">ডিভাইসের স্ক্রিন ক্যাপচার অনুমতির জন্য অপেক্ষা করা হচ্ছে…</p>
    </div>
    <div id="cameraViewerBox" class="hidden bg-black rounded-xl overflow-hidden relative flex items-center justify-center mx-auto"
         style="height: clamp(240px, calc(100vh - 320px), 900px);">
        <video id="cameraVideo" autoplay playsinline muted class="max-w-full max-h-full object-contain"></video>
    </div>
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
    const screenBox = document.getElementById('screenViewerBox');
    const cameraBox = document.getElementById('cameraViewerBox');

    // Shared by both boxes so a paired screen+camera layout always gives
    // them equal, matching height — only the width-vs-aspect-ratio class
    // differs between "solo" (fills the row) and "paired" (9:16, width
    // derived from this same height).
    const VIEWER_BOX_HEIGHT_STYLE = 'height: clamp(240px, calc(100vh - 320px), 900px);';

    /** Anything other than 'off'/'stopped' means the capability's box should be visible. */
    function isCapabilityPresent(capability) {
        const key = tileEls(capability).state?.dataset.stateKey;
        return !!key && key !== 'off' && key !== 'stopped';
    }

    /**
     * The only place either viewer box's classes/style are ever set.
     * Screen-only stays byte-for-byte the original solo shape (`w-full`,
     * no aspect-ratio — see the box's own doc comment above for why a
     * forced aspect ratio is wrong there). The moment camera is ALSO
     * present, both boxes switch to matching `aspect-[9/16]` boxes (width
     * derived from the shared height above) so they sit as two equal
     * large portrait viewers — side by side on `lg:flex-row`, stacked on
     * the default `flex-col` — never touching the existing WebRTC
     * tracks/elements, purely a class/style toggle on their containers.
     */
    function updateViewerLayout() {
        const screenPresent = isCapabilityPresent('screen');
        const cameraPresent = isCapabilityPresent('camera');
        const paired = screenPresent && cameraPresent;

        screenBox.classList.toggle('hidden', !screenPresent);
        screenBox.classList.toggle('w-full', !paired);
        screenBox.classList.toggle('aspect-[9/16]', paired);
        screenBox.setAttribute('style', VIEWER_BOX_HEIGHT_STYLE);

        cameraBox.classList.toggle('hidden', !cameraPresent);
        if (cameraPresent) {
            // Camera alone (screen not present) is just as large as the
            // paired case — same box shape either way.
            cameraBox.classList.add('aspect-[9/16]');
            cameraBox.classList.remove('w-full');
            cameraBox.setAttribute('style', VIEWER_BOX_HEIGHT_STYLE);
        }
    }

    const CAPABILITY_LABELS = { screen: '🎥 Screen', camera: '📷 Camera', microphone: '🎙 Microphone', device_audio: '🔊 Device Audio' };
    const STATE_LABELS_BN = {
        off: 'বন্ধ', starting: 'সংযোগ হচ্ছে…', active: 'স্ট্রিমিং হচ্ছে ✅',
        unavailable: 'অনুপলব্ধ', stopped: 'বন্ধ করা হয়েছে', error: 'ত্রুটি',
        // Distinct from generic 'error' — see WebRtcSessionController's
        // ScreenAuthorizationRequiredException doc comment (Flutter repo):
        // Android's MediaProjection consent genuinely needs the tenant to
        // approve it again on THEIR phone; there is nothing the admin or
        // this app can retry automatically, so this must never look like
        // an ordinary retryable failure.
        authorization_required: '📱 ডিভাইসে অনুমোদন প্রয়োজন',
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
        const { state: stateEl, button } = tileEls(capability);
        if (stateEl) {
            stateEl.textContent = STATE_LABELS_BN[state] ?? state;
            stateEl.dataset.stateKey = state;
        }
        // Keep the Start/Stop button's own label in sync on EVERY state
        // change, not just when it's clicked — a signal-driven change
        // (e.g. replaying signal history after a page reload, or the
        // device reporting a capability stopped/erroring on its own)
        // used to leave the button showing whatever it last rendered at
        // wiring time, disagreeing with the badge right next to it.
        if (button && !button.classList.contains('hidden')) {
            button.textContent = ['starting', 'active'].includes(state) ? 'বন্ধ করুন' : 'চালু করুন';
        }
        // Screen/camera box layout (solo vs. paired 9:16) is entirely
        // driven by these two capabilities' own state — recompute on
        // every transition rather than scattering calls at each of this
        // function's call sites (ontrack, capability-status signals, the
        // initial per-capability loop, and connectionstatechange's badge
        // restore all already call this).
        if (capability === 'screen' || capability === 'camera') updateViewerLayout();
    }

    /**
     * The Start/Stop button for EVERY capability tile — toggles via real
     * capability-start/capability-stop signals on the SAME live session,
     * never a new one, and never just a local mute. Applies uniformly
     * whether or not the capability was part of the session's initial set
     * (see the bottom of this script) — a capability that's already
     * active on page load starts this toggle in its "on" state so the
     * button immediately reads "বন্ধ করুন" and sends a real
     * capability-stop when clicked, matching what the tile's state badge
     * already shows.
     *
     * Earlier this used a SEPARATE local-playback-only mute
     * (`track.enabled = !track.enabled`, never touching the device) for
     * any capability whose track had already arrived via `ontrack` —
     * inherited from the pre-independent-capabilities version of this
     * page, where mic/camera were fixed for a session's whole lifetime
     * and a local mute was the only "stop" available. `ontrack` firing
     * AFTER this function had already wired the real toggle silently
     * overwrote it with that local-only one, so the admin lost the
     * ability to actually stop camera/microphone/device audio capture
     * from the UI the moment its track appeared — confirmed via real
     * production testing (2026-09-15, session 183: clicking what looked
     * like Camera's stop button never sent a capability-stop signal at
     * all). Removed in favor of always using this real toggle.
     *
     * Reads the tile's OWN state badge (`data-state-key`, kept in sync by
     * [setCapabilityState] on every real signal) to decide which signal a
     * click sends, rather than a separately-tracked "active" flag — a
     * flag seeded only from the session's CREATION-time
     * include_screen/include_camera/... columns went stale the moment a
     * page reload happened after a capability had been added/removed via
     * signals since creation (those columns are never updated post-
     * creation — see RemoteSupportService::startSession's own docs),
     * showing e.g. "চালু করুন" for a Screen that was actually already
     * streaming. The state badge is the single source of truth for every
     * OTHER part of this page already; deciding off this too means the
     * button can never disagree with it.
     */
    function wireCapabilityToggle(capability) {
        const { button, state: stateEl } = tileEls(capability);
        if (!button) return;
        const isOn = () => ['starting', 'active'].includes(stateEl?.dataset.stateKey);
        const render = () => {
            button.classList.remove('hidden');
            button.textContent = isOn() ? 'বন্ধ করুন' : 'চালু করুন';
        };
        button.onclick = async () => {
            const wasOn = isOn();
            button.textContent = wasOn ? 'চালু করুন' : 'বন্ধ করুন'; // optimistic; corrected by the next capability-status signal either way
            await postSignal(wasOn ? 'capability-stop' : 'capability-start', capability);
        };
        render();
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
                }
                setCapabilityState(capability, 'active');
            } else if (track.kind === 'audio') {
                const capability = pendingAudioCapabilities.shift() ?? 'microphone';
                remoteAudio.srcObject = event.streams[0];
                setCapabilityState(capability, 'active');
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

    // Every tile gets a real Start/Stop toggle immediately (never waits
    // for a track to arrive before the button becomes clickable — there
    // may never be one for a capability the admin hasn't asked for yet).
    // The state badge itself (which wireCapabilityToggle reads) needs an
    // initial value set explicitly here too — the server only rendered
    // its TEXT (matching the session's CREATION-time include_* columns),
    // never the `data-state-key` the toggle actually reads.
    for (const capability of Object.keys(CAPABILITY_LABELS)) {
        setCapabilityState(capability, initialCapabilities[capability] ? 'starting' : 'off');
        wireCapabilityToggle(capability);
    }

    pollLoop();
})();
</script>
@endsection
