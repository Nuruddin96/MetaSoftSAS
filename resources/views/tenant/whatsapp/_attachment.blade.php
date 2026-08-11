{{-- WhatsApp message content, type-aware. $m: WhatsAppMessage.
     Inbound media is never downloaded/re-hosted (a deliberate Phase 2/3
     decision — see WhatsAppWebhookController::handleIncomingMessage()) —
     only OUTBOUND sends carry a real, renderable attachment_url (the link
     this app generated and sent via WhatsAppSendService). Inbound media
     renders as a safe, type-labeled placeholder instead of a broken/missing
     media tag. Every branch below must render *something* for a non-text
     message — an unrecognized message_type must never leave the bubble
     blank, same "must not crash the conversation page" rule the webhook
     itself already follows for unknown types. --}}
@php
    $mediaLabels = [
        'image' => '📷 ছবি', 'video' => '🎥 ভিডিও', 'audio' => '🎤 অডিও',
        'document' => '📄 ডকুমেন্ট', 'sticker' => '🌟 স্টিকার',
    ];
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
@elseif ($m->attachment_type && isset($mediaLabels[$m->attachment_type]))
    <p class="mt-1.5 text-xs opacity-80">{{ $mediaLabels[$m->attachment_type] }} পাঠিয়েছে{{ $m->attachment_name ? ' ('.$m->attachment_name.')' : '' }} — মিডিয়া এখনো ডাউনলোড করা হয়নি</p>
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
