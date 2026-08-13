{{--
    Left pane of the WhatsApp-style inbox shell — shared by tenant.inbox
    (the "no conversation open" state), whatsapp/show.blade.php, and
    messenger/show.blade.php, so the chat list is always visible, not just
    on a dedicated list page. Extracted from the original inbox/index.blade.php
    (Phase 1 of the inbox redesign) and redesigned (Phase 3): rows no longer
    show the new/contacted/converted/ignored triage status as a pill — that
    enum still exists and still drives $c->unreadCount (see
    UnifiedConversation), it's just no longer the primary visual structure.
    An unread numeric badge + bold name is used instead, WhatsApp-style.

    Expected vars: $conversations, $nextCursor, $hasMore, $channel, $search,
    $unread, $activeConversationKey (string|null — highlights the open row
    when this list renders alongside an already-open thread).

    Known scope limit: the search/unread/channel filters below always link
    back to tenant.inbox — when this list renders alongside an open
    WhatsApp/Messenger thread it always shows the unfiltered, all-channels
    list (filtering is a tenant.inbox-entry-point feature in this pass, not
    carried across into an open conversation's URL).
--}}
@php
    $activeConversationKey ??= null;
    $tabs = [
        ['channel' => null, 'unread' => false, 'label' => 'সব চ্যাট'],
        ['channel' => null, 'unread' => true, 'label' => 'না পড়া'],
        ['channel' => 'whatsapp', 'unread' => false, 'label' => 'WhatsApp'],
        ['channel' => 'messenger', 'unread' => false, 'label' => 'Messenger'],
    ];
    $isTabActive = fn ($tab) => ($channel ?? null) === $tab['channel'] && (bool) ($unread ?? false) === $tab['unread'];
@endphp
<div class="flex flex-col h-full min-h-0">
    <div class="p-4 border-b border-ink/10 shrink-0">
        <h1 class="font-disp font-bold text-lg mb-3">📥 ইনবক্স</h1>

        <form method="GET" action="{{ route('tenant.inbox') }}" class="mb-3">
            @if ($channel ?? null)
                <input type="hidden" name="channel" value="{{ $channel }}">
            @endif
            @if ($unread ?? false)
                <input type="hidden" name="unread" value="1">
            @endif
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-mute"></i>
                <input type="search" name="search" value="{{ $search ?? '' }}" placeholder="নাম বা নম্বর খুঁজুন..."
                       class="w-full rounded-btn border border-ink/15 pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
            </div>
        </form>

        <div class="flex gap-1.5 flex-wrap">
            @foreach ($tabs as $tab)
                <a href="{{ route('tenant.inbox', array_filter(['channel' => $tab['channel'], 'unread' => $tab['unread'] ? 1 : null])) }}"
                   class="px-2.5 py-1 rounded-pill text-xs font-semibold transition {{ $isTabActive($tab) ? 'bg-ink text-white' : 'bg-paper text-mute hover:bg-ink/5' }}">
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </div>

    <x-ui.card id="unifiedConversationList" padding="none" class="divide-y divide-ink/5 flex-1 min-h-0 overflow-y-auto rounded-none border-0"
        data-next-cursor="{{ $nextCursor }}"
        data-has-more="{{ $hasMore ? '1' : '0' }}"
        data-channel="{{ $channel }}"
        data-search="{{ $search }}"
        data-unread="{{ $unread ? '1' : '0' }}"
        data-more-url="{{ route('tenant.inbox.more') }}">
        @forelse ($conversations as $c)
            @php
                $isWhatsapp = $c->channel === 'whatsapp';
                $channelBadge = $isWhatsapp ? '🟢' : '🔵';
                $attachmentPreview = ['image' => '📷 ছবি', 'audio' => '🎤 অডিও', 'video' => '🎥 ভিডিও', 'document' => '📄 ডকুমেন্ট', 'sticker' => '🌟 স্টিকার'][$c->lastMessageAttachmentType] ?? '📎 ফাইল';
                $isActive = $activeConversationKey === $c->conversationKey();
            @endphp
            <a id="conv-{{ $c->conversationKey() }}" href="{{ $c->showUrl }}" data-show-url="{{ $c->showUrl }}"
               data-conversation-key="{{ $c->conversationKey() }}"
               class="conv-row flex items-center gap-3 px-4 py-3 hover:bg-paper/60 transition {{ $isActive ? 'bg-leaf/10' : '' }}">
                <span class="relative shrink-0">
                    <x-ui.avatar :name="$c->customerName" :url="$c->avatarUrl" size="default" />
                    <span class="absolute -bottom-0.5 -right-0.5 text-[10px] leading-none bg-white rounded-full" title="{{ $isWhatsapp ? 'WhatsApp' : 'Messenger' }}">{{ $channelBadge }}</span>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <p class="conv-name truncate {{ $c->unreadCount > 0 ? 'font-bold text-ink' : 'font-medium text-ink/90' }}">{{ $c->customerName ?: 'অজানা কাস্টমার' }}</p>
                        <p class="conv-time text-[11px] text-mute shrink-0">{{ $c->lastMessageAt->diffForHumans(null, true) }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-2 mt-0.5">
                        <p class="conv-msg text-xs {{ $c->unreadCount > 0 ? 'text-ink/80 font-medium' : 'text-mute' }} truncate">
                            {{ $c->lastMessageDirection === 'out' ? 'আপনি: ' : '' }}{{ $c->lastMessageText ?: $attachmentPreview }}
                        </p>
                        @if ($c->unreadCount > 0)
                            <span class="conv-unread shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-leaf text-white text-[10px] font-bold flex items-center justify-center">{{ $c->unreadCount > 99 ? '99+' : $c->unreadCount }}</span>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div id="unifiedEmptyState" class="px-5 py-16 text-center text-mute text-sm">
                <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 text-mute/40"></i>
                এখনো কোনো মেসেজ আসেনি।
            </div>
        @endforelse
    </x-ui.card>

    <div class="p-3 text-center shrink-0 {{ $hasMore ? '' : 'hidden' }}" id="loadMoreWrap">
        <x-ui.button type="button" id="loadMoreBtn" variant="outline" size="sm">আরও দেখুন</x-ui.button>
    </div>
</div>
