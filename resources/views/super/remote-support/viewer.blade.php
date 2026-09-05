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

{{-- Real media controls — every badge here reflects an ACTUAL WebRTC track
     state (requested-for-this-session, device permission as last reported
     by heartbeat, or an actual RTCTrackEvent received), never a UI-only
     toggle. See docs/permission-flow.md — MediaProjection/camera/mic are
     never pre-granted; a track only exists here once the device's own
     user actually consented on-device. --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-4">
    <div class="bg-white rounded-xl border border-ink/5 p-4">
        <p class="text-xs text-mute mb-1">🎥 স্ক্রিন</p>
        <p id="screenState" class="font-medium text-sm">সংযোগ হচ্ছে…</p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-4">
        <div class="flex items-center justify-between">
            <p class="text-xs text-mute mb-1">🎙 মাইক্রোফোন</p>
            <button id="micToggleBtn" class="hidden text-[11px] px-2 py-1 rounded bg-ink/5 hover:bg-ink/10">শোনা বন্ধ করুন</button>
        </div>
        <p id="micState" class="font-medium text-sm">
            @if (! $session->include_microphone)
                এই সেশনে অনুরোধ করা হয়নি
            @elseif (! $micPermitted)
                ডিভাইসে অনুমতি নেই
            @else
                সংযোগ হচ্ছে…
            @endif
        </p>
    </div>
    <div class="bg-white rounded-xl border border-ink/5 p-4">
        <div class="flex items-center justify-between">
            <p class="text-xs text-mute mb-1">📷 ক্যামেরা</p>
            <button id="cameraToggleBtn" class="hidden text-[11px] px-2 py-1 rounded bg-ink/5 hover:bg-ink/10">দেখা বন্ধ করুন</button>
        </div>
        <p id="cameraState" class="font-medium text-sm">
            @if (! $session->include_camera)
                এই সেশনে অনুরোধ করা হয়নি
            @elseif (! $cameraPermitted)
                ডিভাইসে অনুমতি নেই
            @else
                সংযোগ হচ্ছে…
            @endif
        </p>
    </div>
</div>

<p class="text-mute text-xs mt-3">
    ডিভাইসে Android-এর নিজস্ব স্ক্রিন-ক্যাপচার সম্মতি ডায়ালগ ও রেকর্ডিং ইন্ডিকেটর দেখানো বাধ্যতামূলক — এটি এড়িয়ে যাওয়া যায় না
    (দেখুন docs/permission-flow.md)। ডিভাইসের ব্যবহারকারী অনুমতি না দিলে ভিডিও কখনো শুরু হবে না। "শোনা/দেখা বন্ধ করুন" শুধু
    এই ব্রাউজারে প্লেব্যাক বন্ধ করে — ডিভাইসের ক্যাপচার বন্ধ করতে পুরো সেশনই বন্ধ করতে হবে।
</p>

@php
    $signalSendUrl = route('super.remote-support.session.signal.send', [$tenant, $device, $session]);
    $signalPollUrl = route('super.remote-support.session.signal.poll', [$tenant, $device, $session]);
    $stopUrl = route('super.remote-support.session.stop', [$tenant, $device, $session]);
@endphp

<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        ?? '{{ csrf_token() }}';
    const sendUrl = @json($signalSendUrl);
    const pollUrl = @json($signalPollUrl);
    const stopUrl = @json($stopUrl);
    const iceServers = @json($iceServers);

    const video = document.getElementById('remoteVideo');
    const cameraVideo = document.getElementById('cameraVideo');
    const remoteAudio = document.getElementById('remoteAudio');
    const waitingNote = document.getElementById('waitingNote');
    const connStatus = document.getElementById('connStatus');
    const stopBtn = document.getElementById('stopBtn');
    const reconnectBtn = document.getElementById('reconnectBtn');
    const screenState = document.getElementById('screenState');
    const micState = document.getElementById('micState');
    const cameraState = document.getElementById('cameraState');
    const micToggleBtn = document.getElementById('micToggleBtn');
    const cameraToggleBtn = document.getElementById('cameraToggleBtn');

    let since = 0;
    let polling = true;
    let pc = null;
    let videoTracksSeen = 0; // device adds tracks screen-first, then mic, then camera (WebRtcSessionController.start()) — used to tell the screen video track apart from the camera one, both `kind: 'video'`.

    function setStatus(text, cls) {
        connStatus.textContent = text;
        connStatus.className = 'px-3 py-1.5 rounded-full font-medium ' + cls;
    }

    /** Never leave a stale "স্ট্রিমিং হচ্ছে" badge once the connection actually ends — reflects real track state, not just the initial event. */
    function markTracksStopped() {
        if (videoTracksSeen > 0) screenState.textContent = 'সংযোগ বিচ্ছিন্ন';
        if (!micToggleBtn.classList.contains('hidden')) micState.textContent = 'সংযোগ বিচ্ছিন্ন';
        if (!cameraToggleBtn.classList.contains('hidden')) cameraState.textContent = 'সংযোগ বিচ্ছিন্ন';
        micToggleBtn.classList.add('hidden');
        cameraToggleBtn.classList.add('hidden');
    }

    /** Toggles LOCAL playback only (event.track.enabled on the already-received track) — see the page's own note on why this can't stop the device's actual capture without a new signal type. */
    function wireLocalMuteToggle(button, mediaElement, onLabel, offLabel) {
        button.classList.remove('hidden');
        button.textContent = onLabel;
        button.onclick = () => {
            const track = mediaElement.srcObject?.getTracks()?.[0];
            if (!track) return;
            track.enabled = !track.enabled;
            button.textContent = track.enabled ? onLabel : offLabel;
        };
    }

    /**
     * The device is the offering side (it owns the media — the screen
     * capture track). This admin viewer is purely the answering side: it
     * only ever creates an RTCPeerConnection once an 'offer' signal
     * actually arrives, so opening this page never itself triggers any
     * capture on the device — see permission-flow.md, MediaProjection
     * consent is requested on-device only once the device agent decides
     * to answer this session.
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
                videoTracksSeen += 1;
                if (videoTracksSeen === 1) {
                    // First video track = the screen capture (device always
                    // adds it first, before mic/camera) — this IS the real
                    // MediaProjection-backed track, never a screenshot.
                    video.srcObject = event.streams[0];
                    waitingNote.style.display = 'none';
                    screenState.textContent = 'স্ট্রিমিং হচ্ছে ✅';
                } else {
                    // Second video track = camera.
                    cameraVideo.srcObject = event.streams[0];
                    cameraVideo.classList.remove('hidden');
                    cameraState.textContent = 'স্ট্রিমিং হচ্ছে ✅';
                    wireLocalMuteToggle(cameraToggleBtn, cameraVideo, 'দেখা বন্ধ করুন', 'আবার দেখুন');
                }
            } else if (track.kind === 'audio') {
                remoteAudio.srcObject = event.streams[0];
                micState.textContent = 'শোনা যাচ্ছে ✅';
                wireLocalMuteToggle(micToggleBtn, remoteAudio, 'শোনা বন্ধ করুন', 'আবার শুনুন');
            }
        };

        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'connected') {
                setStatus('সংযুক্ত', 'bg-leaf/10 text-leafdk');
                reconnectBtn.disabled = false;
                // `ontrack` does not reliably re-fire for a track that
                // simply survives a renegotiation (confirmed via real
                // on-device testing, 2026-09-05: after a genuine network-
                // change reconnect, connectionState correctly recovered to
                // 'connected' but the per-track badges below stayed stuck
                // on "সংযোগ বিচ্ছিন্ন" forever, since nothing else ever
                // reset them) — restore any badge whose element already
                // has a track playing, rather than waiting on an event
                // that may never come again for an already-established
                // track.
                if (video.srcObject) screenState.textContent = 'স্ট্রিমিং হচ্ছে ✅';
                if (remoteAudio.srcObject) micState.textContent = 'শোনা যাচ্ছে ✅';
                if (cameraVideo.srcObject) cameraState.textContent = 'স্ট্রিমিং হচ্ছে ✅';
            } else if (pc.connectionState === 'disconnected' || pc.connectionState === 'failed') {
                setStatus('সংযোগ বিচ্ছিন্ন — পুনঃসংযোগের চেষ্টা হচ্ছে', 'bg-amber/10 text-amber');
                markTracksStopped();
            } else if (pc.connectionState === 'closed') {
                setStatus('বন্ধ', 'bg-ink/5 text-mute');
                markTracksStopped();
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

    /**
     * `skip` is true only for a historical 'offer' that a LATER offer in
     * the same poll batch already superseded — see pollLoop's own comment
     * for why this matters on a page reload/tab reopen mid-session. Every
     * other signal type is unaffected: this never changes behavior for the
     * common single-offer session, only guards the reload-after-ICE-restart
     * replay case.
     */
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
            markTracksStopped();
            pc?.close();
        }
    }

    async function pollLoop() {
        while (polling) {
            try {
                const res = await fetch(pollUrl + '?since=' + since, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    // A page reload (or reopening this tab mid-session)
                    // starts `since` back at 0, so this batch can contain
                    // the ENTIRE signal history, not just what's new — for
                    // an ordinary single-offer session that's harmless
                    // (there's only one offer to answer), but a session
                    // that already went through an ICE restart before the
                    // reload has TWO 'offer' signals in that history: the
                    // original one and the device's renegotiation offer.
                    // Answering the stale first one here would POST a
                    // second, now-obsolete 'answer' signal after the
                    // device has already moved its own peer connection
                    // past that round — only the latest offer in this
                    // batch is still the device's actual current state, so
                    // only it gets answered; the rest of the batch (every
                    // ice-candidate, 'bye', and the answer itself) is
                    // unaffected and still processed in order.
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
                        markTracksStopped();
                        pc?.close();
                    }
                }
            } catch (e) {
                console.warn('signal poll failed', e);
            }
            await new Promise((r) => setTimeout(r, 1500));
        }
    }

    /**
     * Manual recovery for a connection stuck disconnected/reconnecting —
     * sends a lightweight request signal the device answers by re-running
     * its OWN existing ICE-restart path (same session, same
     * RTCPeerConnection, same already-live screen capture): never a new
     * session, never a fresh MediaProjection/Allow Access prompt, never a
     * second foreground service. This page doesn't create its own new
     * offer — the resulting device-generated offer arrives through the
     * normal poll loop and is answered by the existing 'offer' case in
     * handleSignal(), identical to an automatic reconnect.
     *
     * Disabled for a cooldown after each click (independent of connection
     * state, which may never reach 'connected' if the network is still
     * down) — paired with WebRtcSessionController._performIceRestart()'s
     * own 3-second debounce on the device side, so neither a rapid double
     * click here nor a race with the device's own automatic restart timer
     * can fire two overlapping renegotiations.
     */
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

    pollLoop();
})();
</script>
@endsection
