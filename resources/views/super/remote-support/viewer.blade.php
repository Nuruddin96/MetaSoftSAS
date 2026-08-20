@extends('layouts.super')
@section('title', 'লাইভ স্ক্রিন — '.$tenant->store_name)
@section('content')
<a href="{{ route('super.remote-support.show', $tenant) }}" class="text-mute text-sm hover:underline">← {{ $tenant->store_name }}</a>
<h1 class="font-disp font-bold text-2xl mt-1 mb-4">লাইভ স্ক্রিন — {{ $device->device_model ?: $device->device_uuid }}</h1>

<div class="flex flex-wrap items-center gap-3 mb-4 text-sm">
    <span id="connStatus" class="px-3 py-1.5 rounded-full bg-ink/5 text-mute font-medium">সংযোগ হচ্ছে…</span>
    <span class="px-3 py-1.5 rounded-full bg-ink/5 text-mute">🎙️ মাইক্রোফোন: {{ $session->include_microphone ? 'অনুরোধ করা হয়েছে' : 'বন্ধ' }}</span>
    <span class="px-3 py-1.5 rounded-full bg-ink/5 text-mute">📷 ক্যামেরা: {{ $session->include_camera ? 'অনুরোধ করা হয়েছে' : 'বন্ধ' }}</span>
    <button id="stopBtn" class="ml-auto px-4 py-2 rounded-lg text-sm font-medium bg-red-50 text-red-600">সেশন বন্ধ করুন</button>
</div>

<div class="bg-black rounded-xl overflow-hidden aspect-video flex items-center justify-center">
    <video id="remoteVideo" autoplay playsinline class="w-full h-full object-contain"></video>
    <p id="waitingNote" class="text-white/50 text-sm absolute">ডিভাইসের স্ক্রিন ক্যাপচার অনুমতির জন্য অপেক্ষা করা হচ্ছে…</p>
</div>

<p class="text-mute text-xs mt-3">
    ডিভাইসে Android-এর নিজস্ব স্ক্রিন-ক্যাপচার সম্মতি ডায়ালগ ও রেকর্ডিং ইন্ডিকেটর দেখানো বাধ্যতামূলক — এটি এড়িয়ে যাওয়া যায় না
    (দেখুন docs/permission-flow.md)। ডিভাইসের ব্যবহারকারী অনুমতি না দিলে ভিডিও কখনো শুরু হবে না।
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
    const waitingNote = document.getElementById('waitingNote');
    const connStatus = document.getElementById('connStatus');
    const stopBtn = document.getElementById('stopBtn');

    let since = 0;
    let polling = true;
    let pc = null;

    function setStatus(text, cls) {
        connStatus.textContent = text;
        connStatus.className = 'px-3 py-1.5 rounded-full font-medium ' + cls;
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
            video.srcObject = event.streams[0];
            waitingNote.style.display = 'none';
        };

        pc.onconnectionstatechange = () => {
            if (pc.connectionState === 'connected') {
                setStatus('সংযুক্ত', 'bg-leaf/10 text-leafdk');
            } else if (pc.connectionState === 'disconnected' || pc.connectionState === 'failed') {
                setStatus('সংযোগ বিচ্ছিন্ন — পুনঃসংযোগের চেষ্টা হচ্ছে', 'bg-amber/10 text-amber');
            } else if (pc.connectionState === 'closed') {
                setStatus('বন্ধ', 'bg-ink/5 text-mute');
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

    async function handleSignal(signal) {
        const conn = ensurePeerConnection();

        if (signal.type === 'offer') {
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
            pc?.close();
        }
    }

    async function pollLoop() {
        while (polling) {
            try {
                const res = await fetch(pollUrl + '?since=' + since, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    for (const signal of data.signals) {
                        since = Math.max(since, signal.id);
                        await handleSignal(signal);
                    }
                    if (data.session_status === 'ended') {
                        polling = false;
                        setStatus('সেশন শেষ হয়েছে', 'bg-ink/5 text-mute');
                        pc?.close();
                    }
                }
            } catch (e) {
                console.warn('signal poll failed', e);
            }
            await new Promise((r) => setTimeout(r, 1500));
        }
    }

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
