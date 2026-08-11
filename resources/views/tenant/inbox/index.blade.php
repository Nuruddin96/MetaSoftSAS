@extends('layouts.panel')
@section('title', 'ইনবক্স')
@section('content')
<div class="grid lg:grid-cols-[340px_1fr] gap-0 lg:gap-6">
    <div class="lg:h-[calc(100vh-140px)] lg:sticky lg:top-20 border border-ink/10 rounded-card overflow-hidden bg-white">
        @include('tenant.inbox._list', [
            'conversations' => $conversations, 'nextCursor' => $nextCursor, 'hasMore' => $hasMore,
            'channel' => $channel, 'search' => $search, 'unread' => $unread, 'activeConversationKey' => null,
        ])
    </div>

    <div id="conversationPane" class="hidden lg:flex min-w-0 items-center justify-center lg:h-[calc(100vh-140px)] text-center text-mute">
        <div>
            <i data-lucide="message-square" class="w-10 h-10 mx-auto mb-3 text-mute/40"></i>
            <p class="text-sm">একটি কনভারসেশন বেছে নিন</p>
        </div>
    </div>
</div>

@include('tenant.inbox._shell_scripts')
@push('scripts')
<script>
(function () {
    const container = document.getElementById('unifiedConversationList');
    const loadMoreBtn = document.getElementById('loadMoreBtn');
    if (!container || !loadMoreBtn) return;

    let cursor = container.dataset.nextCursor || '';
    const channel = container.dataset.channel || '';
    const search = container.dataset.search || '';
    const unread = container.dataset.unread === '1';
    const moreUrl = container.dataset.moreUrl;

    const attachmentPreview = { image: '📷 ছবি', audio: '🎤 অডিও', video: '🎥 ভিডিও', document: '📄 ডকুমেন্ট', sticker: '🌟 স্টিকার' };
    const channelBadge = { messenger: '🔵', whatsapp: '🟢' };

    function initials(name) {
        const label = (name || '').trim();
        return label ? label[0].toUpperCase() : '?';
    }

    function appendRow(c) {
        document.getElementById('unifiedEmptyState')?.remove();

        const row = document.createElement('a');
        row.id = 'conv-' + c.conversation_key;
        row.href = c.show_url;
        row.dataset.showUrl = c.show_url;
        row.dataset.conversationKey = c.conversation_key;
        row.className = 'conv-row flex items-center gap-3 px-4 py-3 hover:bg-paper/60 transition';
        row.innerHTML = `
            <span class="relative shrink-0">
                <span class="inline-flex items-center justify-center rounded-full font-bold shrink-0 w-11 h-11 text-sm bg-ink/5 text-ink"></span>
                <span class="absolute -bottom-0.5 -right-0.5 text-[10px] leading-none bg-white rounded-full"></span>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2">
                    <p class="conv-name truncate"></p>
                    <p class="conv-time text-[11px] text-mute shrink-0"></p>
                </div>
                <div class="flex items-center justify-between gap-2 mt-0.5">
                    <p class="conv-msg text-xs text-mute truncate"></p>
                    <span class="conv-unread hidden shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-leaf text-white text-[10px] font-bold items-center justify-center"></span>
                </div>
            </div>`;

        row.querySelector('.rounded-full.font-bold').textContent = initials(c.customer_name);
        row.querySelector('span.absolute').textContent = channelBadge[c.channel] || '';
        row.querySelector('.conv-name').textContent = c.customer_name || 'অজানা কাস্টমার';
        row.querySelector('.conv-name').className = 'conv-name truncate ' + (c.unread_count > 0 ? 'font-bold text-ink' : 'font-medium text-ink/90');
        row.querySelector('.conv-msg').textContent = (c.direction === 'out' ? 'আপনি: ' : '') + (c.message_text || attachmentPreview[c.attachment_type] || '📎 ফাইল');
        row.querySelector('.conv-msg').className = 'conv-msg text-xs truncate ' + (c.unread_count > 0 ? 'text-ink/80 font-medium' : 'text-mute');
        row.querySelector('.conv-time').textContent = c.time_label || '';

        if (c.unread_count > 0) {
            const badge = row.querySelector('.conv-unread');
            badge.textContent = c.unread_count > 99 ? '99+' : c.unread_count;
            badge.classList.remove('hidden');
            badge.classList.add('flex');
        }

        container.appendChild(row);
    }

    loadMoreBtn.addEventListener('click', async () => {
        if (!cursor) return;

        loadMoreBtn.disabled = true;
        try {
            const params = new URLSearchParams({ cursor });
            if (channel) params.set('channel', channel);
            if (search) params.set('search', search);
            if (unread) params.set('unread', '1');

            const res = await fetch(`${moreUrl}?${params.toString()}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();

            (data.conversations || []).forEach(appendRow);
            cursor = data.next_cursor || '';
            document.getElementById('loadMoreWrap')?.classList.toggle('hidden', !data.has_more);
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
