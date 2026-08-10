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
</script>
@endpush
@endonce
