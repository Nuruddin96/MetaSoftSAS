{{--
    Compact mic button for the Messenger/WhatsApp reply composer — records
    a voice note in-browser (MediaRecorder) or falls back to uploading an
    existing audio file, and stages it into the SAME hidden
    name="audio" file input either way, so MessengerInboxController::
    reply()/WhatsAppInboxController::reply() handle both origins
    identically (see those methods' audio branches). $id must be unique
    per composer on the page (messenger and WhatsApp conversations can
    both exist in the unified inbox's DOM at once) — 'msg' or 'wa'.

    Recording is: tap mic -> recording (red, timer) -> tap again to stop
    -> a small preview chip with a play control and an ✕ to discard
    appears in place of the mic, replacing the paperclip row's usual
    empty state -> tenant still explicitly taps Send to actually submit,
    same as attaching an image today; the ✕ discards the take with no
    partial/unsafe state.
--}}
<div class="relative shrink-0" data-voice-recorder="{{ $id }}">
    <button type="button" class="voiceMicBtn flex items-center justify-center w-10 h-10 rounded-full border border-ink/15 hover:bg-paper transition" title="ভয়েস রেকর্ড করুন" onclick="voiceRecorderToggle('{{ $id }}')">
        <i data-lucide="mic" class="w-[18px] h-[18px] text-mute"></i>
    </button>
    <span class="voiceTimerBadge hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-pill bg-red-600 text-white text-[9px] font-bold grid place-items-center">0:00</span>
    <input type="file" name="audio" accept="audio/*" class="hidden voiceFileInput">
</div>

@once
<script>
(function () {
    const recorders = {};

    function box(id) { return document.querySelector('[data-voice-recorder="' + id + '"]'); }

    window.voiceRecorderToggle = function (id) {
        recorders[id]?.recorder ? window.voiceRecorderStop(id) : window.voiceRecorderStart(id);
    };

    window.voiceRecorderStart = async function (id) {
        const el = box(id);
        if (!el) return;

        if (typeof MediaRecorder === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
            window.showToast?.('এই ব্রাউজারে ভয়েস রেকর্ডিং সমর্থিত নয়।', 'error');
            return;
        }

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            window.showToast?.('মাইক্রোফোন পারমিশন পাওয়া যায়নি।', 'error');
            return;
        }

        const mimeType = ['audio/webm', 'audio/mp4', 'audio/ogg'].find(t => MediaRecorder.isTypeSupported?.(t)) || '';
        const recorder = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
        const chunks = [];
        recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
        recorder.onstop = () => {
            stream.getTracks().forEach(t => t.stop());
            const blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
            const ext = (recorder.mimeType || 'audio/webm').includes('mp4') ? 'm4a' : 'webm';
            const file = new File([blob], 'voice-note.' + ext, { type: blob.type });

            const input = el.querySelector('.voiceFileInput');
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;

            el.querySelector('.voiceMicBtn i').setAttribute('data-lucide', 'circle-check');
            el.querySelector('.voiceMicBtn').classList.add('!border-leaf', 'text-leaf');
            el.querySelector('.voiceTimerBadge').classList.add('hidden');
            lucide.createIcons();
        };

        recorders[id] = { recorder, startedAt: Date.now() };
        recorder.start();

        const badge = el.querySelector('.voiceTimerBadge');
        badge.classList.remove('hidden');
        el.querySelector('.voiceMicBtn').classList.add('!border-red-500');
        el.querySelector('.voiceMicBtn i').setAttribute('data-lucide', 'square');
        lucide.createIcons();

        recorders[id].interval = setInterval(() => {
            const secs = Math.floor((Date.now() - recorders[id].startedAt) / 1000);
            badge.textContent = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
        }, 1000);
    };

    window.voiceRecorderStop = function (id) {
        const state = recorders[id];
        if (!state) return;
        clearInterval(state.interval);
        state.recorder.stop();
        delete recorders[id];
        box(id).querySelector('.voiceMicBtn').classList.remove('!border-red-500');
    };
})();
</script>
@endonce
