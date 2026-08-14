<?php

namespace App\Events;

/**
 * Fired once per genuinely new inbound customer message (Messenger or
 * WhatsApp) — never for a tenant's own outbound reply/echo. A plain event
 * class, not the Illuminate\Notifications system: this app doesn't use
 * that (see WebPushService's docblock), so this is just a typed message
 * for SendNewMessagePush to queue off of. Deliberately unified across both
 * channels (channel + externalId instead of two near-identical events) —
 * the notification/dedup logic is identical either way.
 */
class CustomerMessageReceived
{
    public function __construct(
        public int $tenantId,
        public string $channel,
        public string $externalId,
        public ?string $customerName,
    ) {}
}
