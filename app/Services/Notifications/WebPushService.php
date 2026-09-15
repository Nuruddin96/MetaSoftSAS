<?php

namespace App\Services\Notifications;

use App\Models\DevicePushToken;
use App\Models\NotificationLog;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The single per-user "notify" entrypoint — writes the durable
 * [NotificationLog] record once, then fans out across every channel a user
 * has registered: browser Web Push (VAPID) AND, since the FCM push-
 * notifications task, native Android/iOS app push via [FcmSendService].
 * Deliberately ONE call site for both, not two independent services each
 * writing their own log row: a caller (e.g. SendNewMessagePush) must never
 * have to know or care which channels a given user has, and the in-app
 * mobile notification list (Api\Mobile\NotificationController, backed by
 * this same NotificationLog table) must only ever show one row per real
 * event regardless of how many devices it reached.
 *
 * Web Push delivery requires the `minishlink/web-push` composer package
 * (RFC 8291/8292 payload encryption + VAPID request signing) — installed,
 * but VAPID keys are not configured in every environment; send() detects
 * either gap and logs a warning rather than pretending delivery succeeded.
 * FCM delivery is similarly inert until a real Firebase project is wired
 * in — see [FcmSendService]'s own docblock. Both degrade independently:
 * one channel being unconfigured never blocks the other.
 *
 * $payload shape (all keys optional except title/body):
 *   title, body, url (deep link), tag (collapse key), channel/external_id
 *   (WhatsApp/Messenger conversation deep-link — see CustomerMessageReceived),
 *   order_id (order deep-link), icon, badge, requireInteraction (bool),
 *   silent (bool, suppresses sound/vibration for SUMMARY-tier notifications
 *   per the audit's Part 12 priority model).
 */
class WebPushService
{
    public function __construct(
        private NotificationPreferenceService $preferences,
        private FcmSendService $fcm,
    ) {}

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

        $this->sendFcm($user, $payload, $category);
    }

    /**
     * chunk63.sql's own additive-table guard (DevicePushToken::tablesReady())
     * — a missing table (or zero registered devices, the overwhelmingly
     * common case until the mobile app actually registers one) is a clean
     * no-op, never an error; NotificationLog/web-push above already
     * happened regardless.
     */
    private function sendFcm(User $user, array $payload, ?string $category): void
    {
        if (! DevicePushToken::tablesReady()) {
            return;
        }

        $tokens = DevicePushToken::where('user_id', $user->id)->where('is_active', true)->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        // FCM's data payload requires every value to be a string — absent
        // optional fields become '' rather than being omitted, so the
        // Flutter side can always safely read them without a null check.
        $data = [
            'category' => $category ?? 'technical',
            'title' => (string) ($payload['title'] ?? 'MetaSoft'),
            'body' => (string) ($payload['body'] ?? ''),
            'tag' => (string) ($payload['tag'] ?? ''),
            'channel' => (string) ($payload['channel'] ?? ''),
            'external_id' => (string) ($payload['external_id'] ?? ''),
            'order_id' => (string) ($payload['order_id'] ?? ''),
        ];

        $invalidTokens = $this->fcm->sendToTokens($tokens->all(), $data);

        if (! empty($invalidTokens)) {
            DevicePushToken::whereIn('token', $invalidTokens)->update(['is_active' => false]);
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
