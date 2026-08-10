@extends('layouts.panel')
@section('title', $customer->customer_name ?: 'কথোপকথন')
@section('content')
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">{{ $customer->customer_name ?: 'অজানা কাস্টমার' }}</h1>
        <p class="text-xs text-mute">Messenger PSID: {{ $psid }}</p>
    </div>
    <a href="{{ route('tenant.messenger.index') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← ইনবক্সে ফিরুন</a>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <x-ui.card>
            @include('tenant.messenger._thread', ['messages' => $messages])
        </x-ui.card>

        <form method="POST" action="{{ route('tenant.messenger.reply', $psid) }}" enctype="multipart/form-data" class="mt-4 space-y-1.5">
            @csrf
            <div class="flex gap-2">
                <input name="message" placeholder="রিপ্লাই লিখুন..." class="flex-1 rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none">
                <label class="shrink-0 flex items-center justify-center w-10 h-10 rounded-btn border border-ink/15 cursor-pointer hover:bg-paper transition" title="ছবি যুক্ত করুন">
                    🖼️
                    <input type="file" name="image" accept="image/*" class="hidden" onchange="document.getElementById('imgFileName').textContent = this.files[0] ? '📎 ' + this.files[0].name : ''">
                </label>
                <x-ui.button type="submit" variant="accent" size="sm">পাঠান</x-ui.button>
            </div>
            <p id="imgFileName" class="text-xs text-mute"></p>
        </form>
    </div>

    <div class="space-y-4">
        <x-ui.card padding="sm">
            <p class="font-bold text-sm mb-3">স্ট্যাটাস</p>
            <form method="POST" action="{{ route('tenant.messenger.status', $psid) }}">
                @csrf
                <select name="status" onchange="this.form.submit()" class="w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm bg-white">
                    @foreach (['new'=>'নতুন','contacted'=>'যোগাযোগ হয়েছে','converted'=>'অর্ডারে রূপান্তরিত','ignored'=>'বাদ'] as $k=>$v)
                        <option value="{{ $k }}" @selected($customer->status === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </form>
        </x-ui.card>

        @if ($linkedOrder)
            @php
                $orderStatusLabel = ['pending' => 'পেন্ডিং', 'confirmed' => 'কনফার্মড', 'processing' => 'প্রসেসিং', 'shipped' => 'শিপড', 'delivered' => 'ডেলিভারড', 'cancelled' => 'বাতিল', 'returned' => 'রিটার্ন'][$linkedOrder->status] ?? $linkedOrder->status;
            @endphp
            <x-ui.card padding="sm">
                <p class="font-bold text-sm mb-2">🧾 লিংকড অর্ডার</p>
                <a href="{{ route('tenant.orders.show', $linkedOrder) }}"
                   class="block rounded-btn border border-ink/10 px-3 py-2.5 hover:bg-paper/60 transition">
                    <span class="font-semibold text-sm text-leaf">{{ $linkedOrder->order_number }}</span>
                    <span class="text-xs px-2 py-0.5 rounded-pill font-semibold bg-amber/15 text-ink ml-1">{{ $orderStatusLabel }}</span>
                    @if ($linkedOrder->items()->exists())
                        <p class="text-xs text-mute mt-1">{{ number_format($linkedOrder->total) }}৳</p>
                    @else
                        <p class="text-xs text-mute mt-1">প্রোডাক্ট এখনো বাছাই করা হয়নি</p>
                    @endif
                </a>
            </x-ui.card>
        @else
            <x-ui.card padding="sm">
                <p class="font-bold text-sm mb-2">🧾 নতুন অর্ডার</p>
                <p class="text-xs text-mute mb-3">কাস্টমার ফোন নাম্বার দিলে এখানে অর্ডার এমনিতেই তৈরি হয়ে যাবে। প্রয়োজনে ম্যানুয়ালিও শুরু করতে পারেন।</p>
                <a href="{{ route('tenant.orders.create', ['name' => $customer->customer_name, 'channel' => 'facebook']) }}"
                   class="block text-center py-2.5 rounded-btn border border-ink/15 font-semibold text-sm hover:bg-paper transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">ম্যানুয়ালি অর্ডার শুরু করুন</a>
            </x-ui.card>
        @endif
    </div>
</div>

@push('scripts')
<script>
(function () {
    const thread = document.getElementById('threadMessages');
    if (!thread) return;

    let afterId = parseInt(thread.dataset.lastId || '0', 10);
    const psid = @json($psid);

    function buildAttachmentEl(url, type, name) {
        const wrap = document.createElement('div');
        wrap.className = 'mt-1.5';
        const fallback = document.createElement('p');
        fallback.className = 'hidden text-xs opacity-80';

        if (type === 'image') {
            const img = document.createElement('img');
            img.src = url; img.alt = 'ছবি'; img.loading = 'lazy';
            img.className = 'max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer';
            img.onclick = () => openLightbox(url);
            fallback.textContent = '⚠️ ছবিটি আর পাওয়া যাচ্ছে না';
            img.onerror = () => { img.style.display = 'none'; fallback.classList.remove('hidden'); };
            wrap.append(img, fallback);
        } else if (type === 'audio') {
            const audio = document.createElement('audio');
            audio.controls = true; audio.preload = 'none'; audio.className = 'max-w-[240px] h-10';
            const source = document.createElement('source'); source.src = url;
            audio.appendChild(source);
            fallback.textContent = '⚠️ অডিওটি আর পাওয়া যাচ্ছে না';
            audio.onerror = () => { audio.style.display = 'none'; fallback.classList.remove('hidden'); };
            wrap.append(audio, fallback);
        } else if (type === 'video') {
            const video = document.createElement('video');
            video.controls = true; video.preload = 'none'; video.className = 'max-w-[240px] rounded-lg';
            const source = document.createElement('source'); source.src = url;
            video.appendChild(source);
            fallback.textContent = '⚠️ ভিডিওটি আর পাওয়া যাচ্ছে না';
            video.onerror = () => { video.style.display = 'none'; fallback.classList.remove('hidden'); };
            wrap.append(video, fallback);
        } else {
            const a = document.createElement('a');
            a.href = url; a.target = '_blank';
            a.className = 'block underline text-xs';
            a.textContent = '📎 ' + (name || 'এটাচমেন্ট দেখুন');
            wrap.appendChild(a);
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

        if (m.attachment_url) {
            bubble.appendChild(buildAttachmentEl(m.attachment_url, m.attachment_type, m.attachment_name));
        }

        const time = document.createElement('p');
        time.className = 'text-[10px] mt-1 opacity-70';
        time.textContent = m.time_label || '';
        bubble.appendChild(time);

        wrap.appendChild(bubble);
        thread.appendChild(wrap);
    }

    async function poll() {
        try {
            const res = await fetch(`{{ route('tenant.messenger.updates') }}?after_id=${afterId}&psid=${encodeURIComponent(psid)}`, { headers: { Accept: 'application/json' } });
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
