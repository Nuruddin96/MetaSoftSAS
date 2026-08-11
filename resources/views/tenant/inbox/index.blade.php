@extends('layouts.panel')
@section('title', 'ইনবক্স')
@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">📥 ইনবক্স</h1>
<p class="text-xs text-mute mb-4">Messenger ও WhatsApp — দুই চ্যানেলের কনভারসেশন একসাথে, নতুন মেসেজ আগে।</p>

<div class="flex gap-2 mb-4">
    @php
        $tabs = ['' => 'সব', 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp'];
    @endphp
    @foreach ($tabs as $value => $label)
        <a href="{{ route('tenant.inbox', $value ? ['channel' => $value] : []) }}"
           class="px-3 py-1.5 rounded-pill text-xs font-semibold transition {{ ($channel ?? '') === $value ? 'bg-ink text-white' : 'bg-paper text-mute hover:bg-ink/5' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<x-ui.card id="unifiedConversationList" padding="none" class="divide-y divide-ink/5"
    data-next-cursor="{{ $nextCursor }}"
    data-has-more="{{ $hasMore ? '1' : '0' }}"
    data-channel="{{ $channel }}"
    data-more-url="{{ route('tenant.inbox.more') }}">
    @forelse ($conversations as $c)
        @php
            $isWhatsapp = $c->channel === 'whatsapp';
            $channelBadge = $isWhatsapp ? ['🟢', 'WhatsApp'] : ['🔵', 'Messenger'];
            $attachmentPreview = ['image' => '📷 ছবি পাঠিয়েছে', 'audio' => '🎤 অডিও পাঠিয়েছে', 'video' => '🎥 ভিডিও পাঠিয়েছে', 'document' => '📄 ডকুমেন্ট পাঠিয়েছে', 'sticker' => '🌟 স্টিকার পাঠিয়েছে'][$c->lastMessageAttachmentType] ?? '📎 ফাইল পাঠিয়েছে';
        @endphp
        <a id="conv-{{ $c->conversationKey() }}" href="{{ $c->showUrl }}"
           class="conv-row flex items-center justify-between px-5 py-4 hover:bg-paper/60 transition {{ $c->status === 'new' ? 'bg-leaf/5' : '' }}">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="text-xs shrink-0" title="{{ $channelBadge[1] }}">{{ $channelBadge[0] }} {{ $channelBadge[1] }}</span>
                    <p class="conv-name font-medium truncate">{{ $c->customerName ?: 'অজানা কাস্টমার' }}</p>
                    <span class="conv-dot w-2 h-2 rounded-full bg-leaf {{ $c->status === 'new' ? '' : 'hidden' }}"></span>
                </div>
                <p class="conv-msg text-sm text-mute truncate max-w-md">
                    {{ $c->lastMessageDirection === 'out' ? 'আপনি: ' : '' }}{{ $c->lastMessageText ?: $attachmentPreview }}
                </p>
            </div>
            <div class="text-right shrink-0 ml-3">
                <p class="conv-time text-xs text-mute">{{ $c->lastMessageAt->diffForHumans() }}</p>
                <span class="conv-status text-xs px-2.5 py-1 rounded-pill font-semibold {{ ['new' => 'bg-leaf/10 text-leafdk', 'contacted' => 'bg-amber/15 text-ink', 'converted' => 'bg-ink/5 text-mute', 'ignored' => 'bg-red-50 text-red-600'][$c->status] ?? '' }}">
                    {{ ['new' => 'নতুন', 'contacted' => 'যোগাযোগ হয়েছে', 'converted' => 'অর্ডারে রূপান্তরিত', 'ignored' => 'বাদ'][$c->status] ?? $c->status }}
                </span>
            </div>
        </a>
    @empty
        <div id="unifiedEmptyState" class="px-5 py-16 text-center text-mute text-sm">
            <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
            এখনো কোনো মেসেজ আসেনি।
        </div>
    @endforelse
</x-ui.card>

<div class="mt-4 text-center">
    <x-ui.button type="button" id="loadMoreBtn" variant="outline" size="sm" class="{{ $hasMore ? '' : 'hidden' }}">আরও দেখুন</x-ui.button>
</div>

@push('scripts')
<script>
(function () {
    const container = document.getElementById('unifiedConversationList');
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (!container || !loadMoreBtn) return;

    let cursor = container.dataset.nextCursor || '';
    const channel = container.dataset.channel || '';
    const moreUrl = container.dataset.moreUrl;

    const statusLabel = { new: 'নতুন', contacted: 'যোগাযোগ হয়েছে', converted: 'অর্ডারে রূপান্তরিত', ignored: 'বাদ' };
    const statusClass = { new: 'bg-leaf/10 text-leafdk', contacted: 'bg-amber/15 text-ink', converted: 'bg-ink/5 text-mute', ignored: 'bg-red-50 text-red-600' };
    const attachmentPreview = { image: '📷 ছবি পাঠিয়েছে', audio: '🎤 অডিও পাঠিয়েছে', video: '🎥 ভিডিও পাঠিয়েছে', document: '📄 ডকুমেন্ট পাঠিয়েছে', sticker: '🌟 স্টিকার পাঠিয়েছে' };
    const channelBadge = { messenger: '🔵 Messenger', whatsapp: '🟢 WhatsApp' };

    function appendRow(c) {
        document.getElementById('unifiedEmptyState')?.remove();

        const row = document.createElement('a');
        row.id = 'conv-' + c.conversation_key;
        row.href = c.show_url;
        row.className = 'conv-row flex items-center justify-between px-5 py-4 hover:bg-paper/60 transition' + (c.status === 'new' ? ' bg-leaf/5' : '');
        row.innerHTML = `
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="text-xs shrink-0"></span>
                    <p class="conv-name font-medium truncate"></p>
                    <span class="conv-dot w-2 h-2 rounded-full bg-leaf ${c.status === 'new' ? '' : 'hidden'}"></span>
                </div>
                <p class="conv-msg text-sm text-mute truncate max-w-md"></p>
            </div>
            <div class="text-right shrink-0 ml-3">
                <p class="conv-time text-xs text-mute"></p>
                <span class="conv-status text-xs px-2.5 py-1 rounded-pill font-semibold"></span>
            </div>`;

        row.querySelector('.text-xs.shrink-0').textContent = channelBadge[c.channel] || c.channel;
        row.querySelector('.conv-name').textContent = c.customer_name || 'অজানা কাস্টমার';
        row.querySelector('.conv-msg').textContent = (c.direction === 'out' ? 'আপনি: ' : '') + (c.message_text || attachmentPreview[c.attachment_type] || '📎 ফাইল পাঠিয়েছে');
        row.querySelector('.conv-time').textContent = c.time_label;
        const statusEl = row.querySelector('.conv-status');
        statusEl.textContent = statusLabel[c.status] || c.status;
        statusEl.className = 'conv-status text-xs px-2.5 py-1 rounded-pill font-semibold ' + (statusClass[c.status] || '');

        container.appendChild(row);
    }

    loadMoreBtn.addEventListener('click', async () => {
        if (!cursor) return;

        loadMoreBtn.disabled = true;
        try {
            const params = new URLSearchParams({ cursor });
            if (channel) params.set('channel', channel);

            const res = await fetch(`${moreUrl}?${params.toString()}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();

            (data.conversations || []).forEach(appendRow);
            cursor = data.next_cursor || '';
            loadMoreBtn.classList.toggle('hidden', !data.has_more);
        } catch (e) {
            // silent — tenant can just click again
        } finally {
            loadMoreBtn.disabled = false;
        }
    });
})();
</script>
@endpush
@endsection
