{{-- WhatsApp message content, type-aware. $m: WhatsAppMessage.
     OUTBOUND sends carry a real, renderable attachment_url (the link this
     app generated and sent via WhatsAppSendService). INBOUND media has no
     stored copy — it's proxied live through WhatsAppInboxController::media()
     -> WhatsAppMediaService on every render (see that service's docblock for
     why: no confirmed background queue on shared hosting, so downloading at
     webhook time was ruled out; a live per-view proxy needs no storage and
     costs nothing until someone actually opens the thread). Falls back to a
     type-labeled placeholder only when no media id could be resolved from
     raw_payload at all (a genuinely malformed/legacy payload) — every branch
     below must render *something* for a non-text message, same "must not
     crash the conversation page" rule the webhook itself already follows
     for unknown types. --}}
@php
    $mediaLabels = [
        'image' => '📷 ছবি', 'video' => '🎥 ভিডিও', 'audio' => '🎤 অডিও',
        'document' => '📄 ডকুমেন্ট', 'sticker' => '🌟 স্টিকার',
    ];
    $inboundMediaId = $m->direction === 'in' ? $m->inboundMediaId() : null;
    $inboundMediaUrl = $inboundMediaId ? route('tenant.whatsapp.media', $m->id) : null;
@endphp

@if ($m->direction === 'out' && $m->attachment_url)
    <div class="mt-1.5">
        @if ($m->attachment_type === 'image')
            <img src="{{ $m->attachment_url }}" alt="ছবি" loading="lazy"
                 class="max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer"
                 onclick="openWaLightbox('{{ $m->attachment_url }}')"
                 onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden')">
            <p class="hidden text-xs opacity-80">⚠️ ছবিটি আর পাওয়া যাচ্ছে না</p>
        @elseif ($m->attachment_type === 'video')
            <video controls preload="none" class="max-w-[240px] rounded-lg">
                <source src="{{ $m->attachment_url }}">
            </video>
        @elseif ($m->attachment_type === 'audio')
            <audio controls preload="none" class="max-w-[240px]">
                <source src="{{ $m->attachment_url }}">
            </audio>
        @else
            <a href="{{ $m->attachment_url }}" target="_blank" class="block underline text-xs">📎 {{ $m->attachment_name ?: 'ফাইল দেখুন' }}</a>
        @endif
    </div>
@elseif ($inboundMediaUrl && $m->attachment_type === 'image')
    <div class="mt-1.5">
        <img src="{{ $inboundMediaUrl }}" alt="ছবি" loading="lazy"
             class="max-w-[220px] max-h-[220px] rounded-lg border border-ink/10 object-cover cursor-pointer"
             onclick="openWaLightbox('{{ $inboundMediaUrl }}')"
             onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden')">
        <p class="hidden text-xs opacity-80">⚠️ ছবিটি আর পাওয়া যাচ্ছে না (মেয়াদোত্তীর্ণ)</p>
    </div>
@elseif ($inboundMediaUrl && $m->attachment_type === 'video')
    <video controls preload="none" class="mt-1.5 max-w-[240px] rounded-lg">
        <source src="{{ $inboundMediaUrl }}">
    </video>
@elseif ($inboundMediaUrl && $m->attachment_type === 'audio')
    <audio controls preload="none" class="mt-1.5 max-w-[240px]">
        <source src="{{ $inboundMediaUrl }}">
    </audio>
@elseif ($inboundMediaUrl)
    <a href="{{ $inboundMediaUrl }}" target="_blank" class="mt-1.5 block underline text-xs">📎 {{ $m->attachment_name ?: (($mediaLabels[$m->attachment_type] ?? 'ফাইল').' দেখুন') }}</a>
@elseif ($m->attachment_type && isset($mediaLabels[$m->attachment_type]))
    <p class="mt-1.5 text-xs opacity-80">{{ $mediaLabels[$m->attachment_type] }} পাঠিয়েছে{{ $m->attachment_name ? ' ('.$m->attachment_name.')' : '' }} — মিডিয়া লোড করা যায়নি</p>
@elseif ($m->message_type === 'location')
    @php $loc = $m->raw_payload['location'] ?? null; @endphp
    <p class="mt-1.5 text-xs opacity-80">
        📍 লোকেশন পাঠিয়েছে
        @if ($loc && isset($loc['latitude'], $loc['longitude']))
            ({{ $loc['latitude'] }}, {{ $loc['longitude'] }})
        @endif
    </p>
@elseif ($m->message_type && $m->message_type !== 'text' && ! $m->message_text)
    <p class="mt-1.5 text-xs opacity-80">📎 এই ধরনের মেসেজ এখনো সমর্থিত নয়</p>
@endif
