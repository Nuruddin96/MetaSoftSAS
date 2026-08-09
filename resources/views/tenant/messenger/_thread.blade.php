{{-- Shared Messenger message-bubble thread, used by both messenger/show.blade.php
     (the inbox conversation view) and orders/show.blade.php (an order that
     originated from Messenger) — one message UI, not duplicated into a
     second chat system. $messages: Collection<MessengerMessage>, oldest first. --}}
@php $lastMessageId = optional($messages->last())->id ?? 0; @endphp
<div id="threadMessages" data-last-id="{{ $lastMessageId }}" class="space-y-4 max-h-[500px] overflow-y-auto">
    @foreach ($messages as $m)
        <div class="msg-bubble flex {{ $m->direction === 'out' ? 'justify-end' : 'justify-start' }}" data-id="{{ $m->id }}">
            <div class="max-w-md {{ $m->direction === 'out' ? 'bg-leaf text-white' : 'bg-paper text-ink' }} rounded-card px-4 py-2.5 text-sm">
                @if ($m->message_text){{ $m->message_text }}@endif
                @if ($m->attachment_url)
                    <a href="{{ $m->attachment_url }}" target="_blank" class="block underline text-xs mt-1">📎 এটাচমেন্ট দেখুন</a>
                @endif
                <p class="text-[10px] mt-1 opacity-70">{{ $m->created_at?->format('d M, h:i A') }}</p>
            </div>
        </div>
    @endforeach
</div>
