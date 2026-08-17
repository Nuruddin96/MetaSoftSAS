{{-- WhatsApp message-bubble thread — mirrors tenant/messenger/_thread.blade.php's
     shape, kept as its own file rather than shared: WhatsApp's delivery-
     status ticks and type-aware placeholder rendering (see _attachment.blade.php)
     have no Messenger equivalent. $messages: Collection<WhatsAppMessage>, oldest first. --}}
@php
    $lastMessageId = optional($messages->last())->id ?? 0;
    $lastDate = null;
    $dateLabel = fn ($carbon) => $carbon->isToday() ? 'আজ' : ($carbon->isYesterday() ? 'গতকাল' : $carbon->translatedFormat('d F, Y'));
@endphp
<div id="waThreadMessages" data-last-id="{{ $lastMessageId }}" class="space-y-1.5 h-full overflow-y-auto overflow-x-hidden px-1">
    @foreach ($messages as $m)
        @php $msgDate = $m->created_at?->toDateString(); @endphp
        @if ($msgDate && $msgDate !== $lastDate)
            @php $lastDate = $msgDate; @endphp
            <div class="flex justify-center my-3">
                <span class="text-[11px] font-medium bg-ink/5 text-mute px-2.5 py-1 rounded-pill">{{ $dateLabel($m->created_at) }}</span>
            </div>
        @endif
        <div class="msg-bubble flex {{ $m->direction === 'out' ? 'justify-end' : 'justify-start' }} mb-1.5" data-id="{{ $m->id }}">
            <div class="max-w-[85%] sm:max-w-md break-words {{ $m->direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink' }} rounded-card px-4 py-2.5 text-sm">
                @if ($m->message_text){{ $m->message_text }}@endif
                @include('tenant.whatsapp._attachment', ['m' => $m])
                <div class="flex items-center gap-1.5 mt-1">
                    <p class="text-[10px] opacity-70">{{ $m->created_at?->format('d M, h:i A') }}</p>
                    @if ($m->direction === 'out' && $m->delivery_status)
                        <span class="text-[10px] opacity-70">{{ ['sent' => '✓ পাঠানো হয়েছে', 'delivered' => '✓✓ ডেলিভার হয়েছে', 'read' => '✓✓ পড়া হয়েছে', 'failed' => '⚠️ ব্যর্থ'][$m->delivery_status] ?? $m->delivery_status }}</span>
                    @endif
                </div>
                @if ($m->delivery_status === 'failed' && $m->error_message)
                    <p class="text-[10px] mt-0.5 {{ $m->direction === 'out' ? 'text-red-100' : 'text-red-600' }}">{{ $m->error_message }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>

@once
<div id="waLightboxOverlay" class="hidden fixed inset-0 z-50 bg-black/80 items-center justify-center p-4" onclick="closeWaLightbox()">
    <img id="waLightboxImg" src="" alt="ছবি" class="max-w-full max-h-full rounded-lg">
</div>
@push('scripts')
<script>
    function openWaLightbox(url) {
        document.getElementById('waLightboxImg').src = url;
        const overlay = document.getElementById('waLightboxOverlay');
        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    }
    function closeWaLightbox() {
        const overlay = document.getElementById('waLightboxOverlay');
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        document.getElementById('waLightboxImg').src = '';
    }
</script>
@endpush
@endonce
