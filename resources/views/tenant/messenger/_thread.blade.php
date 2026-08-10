{{-- Shared Messenger message-bubble thread, used by both messenger/show.blade.php
     (the inbox conversation view) and orders/show.blade.php (an order that
     originated from Messenger) — one message UI, not duplicated into a
     second chat system. $messages: Collection<MessengerMessage>, oldest first. --}}
@php $lastMessageId = optional($messages->last())->id ?? 0; @endphp
<div id="threadMessages" data-last-id="{{ $lastMessageId }}" class="space-y-4 max-h-[500px] overflow-y-auto overflow-x-hidden">
    @foreach ($messages as $m)
        <div class="msg-bubble flex {{ $m->direction === 'out' ? 'justify-end' : 'justify-start' }}" data-id="{{ $m->id }}">
            <div class="max-w-[85%] sm:max-w-md break-words {{ $m->direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink' }} rounded-card px-4 py-2.5 text-sm">
                @if ($m->message_text){{ $m->message_text }}@endif
                @if ($m->attachment_url)
                    @include('tenant.messenger._attachment', ['url' => $m->attachment_url, 'type' => $m->attachment_type, 'name' => $m->attachment_name])
                @endif
                <p class="text-[10px] mt-1 opacity-70">{{ $m->created_at?->format('d M, h:i A') }}</p>
            </div>
        </div>
    @endforeach
</div>

@once
<div id="lightboxOverlay" class="hidden fixed inset-0 z-50 bg-black/80 items-center justify-center p-4" onclick="closeLightbox()">
    <img id="lightboxImg" src="" alt="ছবি" class="max-w-full max-h-full rounded-lg">
</div>
@push('scripts')
<script>
    function openLightbox(url) {
        document.getElementById('lightboxImg').src = url;
        const overlay = document.getElementById('lightboxOverlay');
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    }
    function closeLightbox() {
        const overlay = document.getElementById('lightboxOverlay');
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        document.getElementById('lightboxImg').src = '';
    }

    // Messenger-style voice/audio bubble: play button + seekable progress +
    // duration, built on a real <audio> element kept out of the DOM (no
    // native controls, so it looks the same on mobile/desktop/every
    // browser). Mounts into any ".msgr-audio" container — used both for
    // server-rendered history (below) and messages appended live by the
    // inbox's polling script (tenant/messenger/show.blade.php).
    function mountMessengerAudio(container, url) {
        if (!container || container.dataset.mounted) return;
        container.dataset.mounted = '1';

        container.innerHTML = `
            <div class="msgr-audio-player flex items-center gap-2 bg-black/5 rounded-full pl-1 pr-3 py-1.5 max-w-[240px]">
                <button type="button" class="play-btn shrink-0 w-8 h-8 rounded-full bg-leaf text-white flex items-center justify-center transition active:scale-95" aria-label="প্লে করুন">
                    <span class="play-icon leading-none">▶</span>
                    <span class="pause-icon leading-none hidden">⏸</span>
                    <span class="spinner leading-none hidden animate-spin">◌</span>
                </button>
                <div class="flex-1 min-w-0">
                    <div class="progress-track h-1.5 rounded-full bg-black/10 relative cursor-pointer">
                        <div class="progress-fill h-1.5 rounded-full bg-leaf" style="width:0%"></div>
                    </div>
                </div>
                <span class="duration text-[10px] tabular-nums opacity-70 shrink-0 min-w-[30px] text-right">0:00</span>
            </div>
            <p class="error-msg hidden text-xs opacity-80 mt-1">⚠️ অডিওটি আর পাওয়া যাচ্ছে না</p>
        `;

        const player = container.querySelector('.msgr-audio-player');
        const playBtn = container.querySelector('.play-btn');
        const playIcon = container.querySelector('.play-icon');
        const pauseIcon = container.querySelector('.pause-icon');
        const spinner = container.querySelector('.spinner');
        const fill = container.querySelector('.progress-fill');
        const track = container.querySelector('.progress-track');
        const durationEl = container.querySelector('.duration');
        const errorMsg = container.querySelector('.error-msg');

        const audio = new Audio();
        audio.preload = 'none';
        const source = document.createElement('source');
        source.src = url;
        const ext = (url.split('?')[0].split('.').pop() || '').toLowerCase();
        const mimeByExt = { mp3: 'audio/mpeg', m4a: 'audio/mp4', aac: 'audio/aac', ogg: 'audio/ogg', wav: 'audio/wav', caf: 'audio/x-caf' };
        if (mimeByExt[ext]) source.type = mimeByExt[ext];
        audio.appendChild(source);

        function formatTime(seconds) {
            if (!isFinite(seconds) || seconds < 0) return '0:00';
            const m = Math.floor(seconds / 60);
            const s = Math.floor(seconds % 60).toString().padStart(2, '0');
            return `${m}:${s}`;
        }

        function showError() {
            player.classList.add('hidden');
            errorMsg.classList.remove('hidden');
        }

        function setIcon(state) {
            playIcon.classList.toggle('hidden', state !== 'play');
            pauseIcon.classList.toggle('hidden', state !== 'pause');
            spinner.classList.toggle('hidden', state !== 'loading');
        }

        audio.addEventListener('loadedmetadata', () => {
            if (isFinite(audio.duration)) durationEl.textContent = formatTime(audio.duration);
        });
        audio.addEventListener('timeupdate', () => {
            if (audio.duration) fill.style.width = (audio.currentTime / audio.duration * 100) + '%';
            if (!audio.paused && isFinite(audio.duration)) durationEl.textContent = formatTime(audio.duration - audio.currentTime);
        });
        audio.addEventListener('ended', () => {
            setIcon('play');
            fill.style.width = '0%';
            if (isFinite(audio.duration)) durationEl.textContent = formatTime(audio.duration);
        });
        audio.addEventListener('waiting', () => setIcon('loading'));
        audio.addEventListener('canplay', () => { if (!audio.paused) setIcon('pause'); });
        audio.addEventListener('error', showError);

        playBtn.addEventListener('click', () => {
            // Messenger plays one voice clip at a time — pause any other
            // currently-playing bubble before starting this one.
            document.querySelectorAll('.msgr-audio').forEach((el) => {
                if (el !== container && el._msgrAudio && !el._msgrAudio.paused) el._msgrAudio.pause();
            });

            if (audio.paused) {
                setIcon('loading');
                audio.play().then(() => setIcon('pause')).catch(showError);
            } else {
                audio.pause();
                setIcon('play');
            }
        });

        track.addEventListener('click', (e) => {
            if (!audio.duration) return;
            const rect = track.getBoundingClientRect();
            const ratio = Math.min(Math.max((e.clientX - rect.left) / rect.width, 0), 1);
            audio.currentTime = ratio * audio.duration;
        });

        container._msgrAudio = audio;
    }

    function mountAllMessengerAudio(root) {
        (root || document).querySelectorAll('.msgr-audio').forEach((el) => mountMessengerAudio(el, el.dataset.url));
    }

    document.addEventListener('DOMContentLoaded', () => mountAllMessengerAudio());
</script>
@endpush
@endonce
