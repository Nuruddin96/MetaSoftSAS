@extends('layouts.panel')
@section('title', $customer->customer_name ?: 'কথোপকথন')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">💬 {{ $customer->customer_name ?: 'অজানা কাস্টমার' }}</h1>
        <p class="text-xs text-mute">WhatsApp: {{ $waId }}</p>
    </div>
    <a href="{{ route('tenant.inbox') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← ইনবক্সে ফিরুন</a>
</div>

@if (! $connected)
    <x-ui.card tone="amber" padding="sm" class="text-sm mb-6">
        এই WhatsApp নম্বরটি বর্তমানে ডিসকানেক্টেড — রিপ্লাই পাঠানো যাবে না। <a href="{{ route('tenant.settings') }}" class="text-leafdk font-semibold hover:underline">সেটিংসে গিয়ে আবার কানেক্ট করুন</a>।
    </x-ui.card>
@endif

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <x-ui.card>
            @include('tenant.whatsapp._thread', ['messages' => $messages])
        </x-ui.card>

        <form method="POST" action="{{ route('tenant.whatsapp.reply', $waId) }}" enctype="multipart/form-data" class="mt-4 space-y-1.5">
            @csrf
            <div class="flex gap-2">
                <input name="message" placeholder="রিপ্লাই লিখুন..." class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
                <label class="shrink-0 flex items-center justify-center w-10 h-10 rounded-btn border border-ink/15 cursor-pointer hover:bg-paper transition" title="ছবি যুক্ত করুন">
                    🖼️
                    <input type="file" name="image" accept="image/*" class="hidden" onchange="document.getElementById('waImgFileName').textContent = this.files[0] ? '📎 ' + this.files[0].name : ''">
                </label>
                <x-ui.button type="submit" variant="accent" size="sm">পাঠান</x-ui.button>
            </div>
            <p id="waImgFileName" class="text-xs text-mute"></p>
        </form>
    </div>

    <div class="space-y-4">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">স্ট্যাটাস</p>
            <form method="POST" action="{{ route('tenant.whatsapp.status', $waId) }}">
                @csrf
                <select name="status" onchange="this.form.submit()" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm bg-white">
                    @foreach (['new' => 'নতুন', 'contacted' => 'যোগাযোগ হয়েছে', 'converted' => 'অর্ডারে রূপান্তরিত', 'ignored' => 'বাদ'] as $k => $v)
                        <option value="{{ $k }}" @selected($customer->status === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </form>
        </x-ui.card>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const thread = document.getElementById('waThreadMessages');
    if (!thread) return;

    let afterId = parseInt(thread.dataset.lastId || '0', 10);
    const waId = @json($waId);
    const deliveryLabel = { sent: '✓ পাঠানো হয়েছে', delivered: '✓✓ ডেলিভার হয়েছে', read: '✓✓ পড়া হয়েছে', failed: '⚠️ ব্যর্থ' };

    function buildAttachmentEl(m) {
        const wrap = document.createElement('div');
        wrap.className = 'mt-1.5';

        // Outbound (our own durable URL) and inbound (live-proxied through
        // WhatsAppInboxController::media()) both point at a real,
        // browser-fetchable URL by the time they reach this function — only
        // the source field differs, so both share the same render branches.
        const mediaUrl = (m.direction === 'out' ? m.attachment_url : m.inbound_media_url) || null;

        if (mediaUrl && m.attachment_type === 'image') {
            const img = document.createElement('img');
            img.src = mediaUrl; img.alt = 'ছবি'; img.loading = 'lazy';
            img.className = 'max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer';
            img.onclick = () => openWaLightbox(mediaUrl);
            wrap.appendChild(img);
        } else if (mediaUrl && m.attachment_type === 'video') {
            const video = document.createElement('video');
            video.controls = true; video.preload = 'none';
            video.className = 'max-w-[240px] rounded-lg';
            const source = document.createElement('source');
            source.src = mediaUrl;
            video.appendChild(source);
            wrap.appendChild(video);
        } else if (mediaUrl && m.attachment_type === 'audio') {
            const audio = document.createElement('audio');
            audio.controls = true; audio.preload = 'none';
            audio.className = 'max-w-[240px]';
            const source = document.createElement('source');
            source.src = mediaUrl;
            audio.appendChild(source);
            wrap.appendChild(audio);
        } else if (mediaUrl) {
            const a = document.createElement('a');
            a.href = mediaUrl; a.target = '_blank';
            a.className = 'block underline text-xs';
            a.textContent = '📎 ' + (m.attachment_name || 'ফাইল দেখুন');
            wrap.appendChild(a);
        } else if (m.attachment_type) {
            const p = document.createElement('p');
            p.className = 'text-xs opacity-80';
            p.textContent = 'মিডিয়া পাঠিয়েছে — মিডিয়া লোড করা যায়নি';
            wrap.appendChild(p);
        } else {
            return null;
        }

        return wrap;
    }

    function appendBubble(m) {
        if (document.querySelector(`.msg-bubble[data-id="${m.id}"]`)) return;

        const wrap = document.createElement('div');
        wrap.className = 'msg-bubble flex ' + (m.direction === 'out' ? 'justify-end' : 'justify-start');
        wrap.dataset.id = m.id;

        const bubble = document.createElement('div');
        bubble.className = 'max-w-[85%] sm:max-w-md break-words ' + (m.direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink') + ' rounded-card px-4 py-2.5 text-sm';

        if (m.message_text) {
            const text = document.createElement('div');
            text.textContent = m.message_text;
            bubble.appendChild(text);
        }

        const attachmentEl = buildAttachmentEl(m);
        if (attachmentEl) bubble.appendChild(attachmentEl);

        const meta = document.createElement('div');
        meta.className = 'flex items-center gap-1.5 mt-1';
        const time = document.createElement('p');
        time.className = 'text-[10px] opacity-70';
        time.textContent = m.time_label || '';
        meta.appendChild(time);
        if (m.direction === 'out' && m.delivery_status) {
            const status = document.createElement('span');
            status.className = 'text-[10px] opacity-70';
            status.textContent = deliveryLabel[m.delivery_status] || m.delivery_status;
            meta.appendChild(status);
        }
        bubble.appendChild(meta);

        wrap.appendChild(bubble);
        thread.appendChild(wrap);
    }

    async function poll() {
        try {
            const res = await fetch(`{{ route('tenant.whatsapp.updates') }}?after_id=${afterId}&wa_id=${encodeURIComponent(waId)}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            afterId = data.latest_id;

            const newMessages = data.messages || [];
            if (newMessages.length) {
                newMessages.forEach(appendBubble);
                thread.scrollTop = thread.scrollHeight;
            }
        } catch (e) {
            // silent — next tick tries again
        }
    }

    setInterval(poll, 4000);
})();
</script>
@endpush
@endsection
