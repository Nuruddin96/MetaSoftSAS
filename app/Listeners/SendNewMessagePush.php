<?php

namespace App\Listeners;

use App\Events\CustomerMessageReceived;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\NotificationDedupService;
use App\Services\Notifications\TenantDeepLink;
use App\Services\Notifications\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued (not synchronous) so the webhook controller that dispatches
 * CustomerMessageReceived returns to Meta immediately regardless of how
 * long push delivery takes — same "don't block the request" rule the
 * mobile audit's Part 19 calls out. Runs on the existing cron-driven
 * database queue (routes/console.php), no new infrastructure.
 */
class SendNewMessagePush implements ShouldQueue
{
    public function __construct(
        private WebPushService $push,
        private NotificationDedupService $dedup,
        private TenantDeepLink $deepLink,
    ) {}

    public function handle(CustomerMessageReceived $event): void
    {
        // No currentTenant is bound outside a request — every query here
        // must be explicit and unscoped, same pattern the webhook
        // controllers themselves already use (see WhatsAppWebhookController
        // ::resolvePhoneNumberOwner's docblock).
        $tenant = Tenant::withoutGlobalScopes()->find($event->tenantId);
        if (! $tenant) {
            return;
        }

        $users = User::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('is_active', 1)
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $name = $event->customerName ?: 'কাস্টমার';

        $url = $event->channel === 'whatsapp'
            ? $this->deepLink->build($tenant, 'tenant.whatsapp.show', ['waId' => $event->externalId])
            : $this->deepLink->build($tenant, 'tenant.messenger.show', ['psid' => $event->externalId]);

        foreach ($users as $user) {
            // Tag/dedup identity is tenant_id + user_id + channel +
            // externalId, not just channel + externalId. externalId is a
            // customer-controlled identifier (WhatsApp's is literally a
            // phone number) with no cross-tenant uniqueness guarantee —
            // two different tenants can easily be messaged by the same
            // number. Without tenant_id (and user_id, so two staff at the
            // same tenant get independent counters rather than one
            // silently suppressing/overwriting the other's), the shared
            // cache-backed counter in NotificationDedupService would mix
            // one tenant's/user's message count into another's
            // notification text. Computed per-user (not once, fanned out)
            // for exactly that reason — still exactly one increment per
            // actual new message per recipient, not inflated by looping.
            $tag = "msg:{$event->tenantId}:{$user->id}:{$event->channel}:{$event->externalId}";

            $body = $this->dedup->groupedMessageBody(
                $tag,
                "{$name}: নতুন মেসেজ",
                fn (int $count) => "{$name} {$count}টি নতুন মেসেজ পাঠিয়েছেন",
            );

            $this->push->sendToUser($user, [
                'title' => 'নতুন মেসেজ',
                'body' => $body,
                'tag' => $tag,
                'url' => $url,
            ], category: 'messages');
        }
    }
}
