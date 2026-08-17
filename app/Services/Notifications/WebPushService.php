<?php

namespace App\Services\Notifications;

use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Sends Web Push notifications via VAPID and logs a durable record of each
 * one. MetaSoft is a PWA, not a native app (see the mobile audit, Part A/C)
 * — this is standard browser Web Push, not FCM-in-a-native-SDK.
 *
 * Delivery requires the `minishlink/web-push` composer package (RFC
 * 8291/8292 payload encryption + VAPID request signing) — deliberately NOT
 * installed as part of this pass, per the "don't add a new production
 * dependency without asking" stop condition. Everything that leads up to
 * send() — subscriptions, preferences, events, listeners, deep links,
 * dedup — is fully wired and already exercised by tests against this
 * service's public interface; send() itself detects the missing library,
 * logs a warning, and returns false rather than pretending delivery
 * succeeded. Install the package and this starts actually reaching
 * devices with no other code changes. See the implementation report's
 * "Remaining Work" section.
 *
 * $payload shape (all keys optional except title/body):
 *   title, body, url (deep link), tag (collapse key), icon, badge,
 *   requireInteraction (bool), silent (bool, suppresses sound/vibration
 *   for SUMMARY-tier notifications per the audit's Part 12 priority model).
 */
class WebPushService
{
    public function __construct(private NotificationPreferenceService $preferences) {}

    public function sendToUser(User $user, array $payload, ?string $category = null): void
    {
        // Same additive-table guard every other post-schema.sql feature in
        // this app uses (FacebookPage, WhatsAppPhoneNumber,
        // AiAgentMessageJob, PushSubscription itself) — database/sql/
        // chunk31.sql may not be imported into a given environment yet.
        // Must run before EVERYTHING else below, including the preference
        // check, which itself queries notification_preferences.
        if (! PushSubscription::tablesReady()) {
            Log::warning('WebPushService: notification tables are not present yet — nothing recorded or sent.', [
                'user_id' => $user->id,
                'category' => $category,
            ]);

            return;
        }

        if ($category && ! $this->preferences->isEnabled($user, $category)) {
            return;
        }

        NotificationLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'category' => $category ?? 'technical',
            'title' => $payload['title'] ?? 'MetaSoft',
            'body' => $payload['body'] ?? '',
            'url' => $payload['url'] ?? null,
            'tag' => $payload['tag'] ?? null,
            'data' => $payload,
        ]);

        $subscriptions = PushSubscription::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->send($subscription, $payload);
        }
    }

    public function send(PushSubscription $subscription, array $payload): bool
    {
        if (! class_exists(\Minishlink\WebPush\WebPush::class)) {
            Log::warning('WebPushService: minishlink/web-push is not installed — notification not sent.', [
                'subscription_id' => $subscription->id,
            ]);

            return false;
        }

        $auth = config('services.vapid');
        if (empty($auth['public_key']) || empty($auth['private_key'])) {
            Log::warning('WebPushService: VAPID keys are not configured — notification not sent.');

            return false;
        }

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $auth['subject'],
                'publicKey' => $auth['public_key'],
                'privateKey' => $auth['private_key'],
            ],
        ]);

        $report = $webPush->sendOneNotification(
            \Minishlink\WebPush\Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->p256dh_key,
                'authToken' => $subscription->auth_key,
            ]),
            json_encode($payload)
        );

        $subscription->forceFill(['last_seen_at' => now()])->save();

        if (! $report->isSuccess()) {
            // 404/410 from the push service means the browser dropped this
            // subscription (uninstalled, storage cleared, etc.) — same
            // "invalid subscription" case Phase 5/18 call out. Deactivate
            // rather than delete: keeps the row for diagnostics without
            // retrying a dead endpoint on every future send.
            if ($report->isSubscriptionExpired()) {
                $subscription->forceFill(['is_active' => false])->save();
            } else {
                Log::warning('WebPushService: delivery failed.', [
                    'subscription_id' => $subscription->id,
                    'reason' => $report->getReason(),
                ]);
            }

            return false;
        }

        return true;
    }
}
