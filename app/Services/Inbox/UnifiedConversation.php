<?php

namespace App\Services\Inbox;

use Carbon\Carbon;

/**
 * The canonical read-model row for one line of the unified inbox list —
 * NOT a canonical message. Per Phase 5's design, canonicalization happens
 * only at the conversation-list layer; opening a conversation still loads
 * that channel's own native rows (MessengerMessage/WhatsAppMessage)
 * directly, never through this class.
 *
 * conversationKey is "{channel}:{externalCustomerId}" — a Messenger PSID
 * and a WhatsApp wa_id are different string spaces that could theoretically
 * collide as plain strings, so the channel prefix is what actually
 * guarantees a unique identity, not the external id alone.
 */
final class UnifiedConversation
{
    public function __construct(
        public readonly string $channel, // 'messenger' | 'whatsapp'
        public readonly string $externalCustomerId,
        public readonly ?string $customerName,
        public readonly ?string $lastMessageText,
        public readonly ?string $lastMessageAttachmentType,
        public readonly string $lastMessageDirection, // 'in' | 'out'
        public readonly Carbon $lastMessageAt,
        public readonly int $lastMessageId, // this channel table's own row id — the cursor tie-breaker
        public readonly string $status, // new|contacted|converted|ignored — same enum both channels
        public readonly int $unreadCount,
        public readonly string $showUrl,
    ) {}

    public function conversationKey(): string
    {
        return "{$this->channel}:{$this->externalCustomerId}";
    }

    /** Opaque keyset-pagination cursor pointing just past this row. */
    public function cursorToken(): string
    {
        return $this->lastMessageAt->timestamp.':'.$this->lastMessageId;
    }

    /** @return array<string, mixed> JSON shape for the "load more" AJAX endpoint. */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'conversation_key' => $this->conversationKey(),
            'customer_name' => $this->customerName,
            'message_text' => $this->lastMessageText,
            'attachment_type' => $this->lastMessageAttachmentType,
            'direction' => $this->lastMessageDirection,
            'status' => $this->status,
            'unread_count' => $this->unreadCount,
            'time_label' => $this->lastMessageAt->diffForHumans(),
            'show_url' => $this->showUrl,
        ];
    }
}
