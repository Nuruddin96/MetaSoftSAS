<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;

/**
 * Two independent spam-control patterns, both cache-backed (no new table —
 * this is transient, self-expiring state):
 *
 *  - groupedMessageBody(): several inbound messages from the same
 *    conversation within the window collapse into one updated notification
 *    ("Rahim sent 3 new messages") instead of stacking N separate pushes.
 *    Paired with the `tag` field on the push payload itself (see
 *    WebPushService/PwaServiceWorkerBuilder), which is what makes the
 *    browser replace rather than pile up the visible notification.
 *
 *  - withinCooldown(): a generic "don't fire this key again within N
 *    seconds" guard for anything that can flap (e.g. an order moving
 *    through several statuses within seconds of an import/bulk update).
 */
class NotificationDedupService
{
    public function groupedMessageBody(string $tag, string $singleBody, callable $groupedBody, int $ttlMinutes = 15): string
    {
        $key = "push_dedup:{$tag}";
        $count = ((int) Cache::get($key, 0)) + 1;
        Cache::put($key, $count, now()->addMinutes($ttlMinutes));

        return $count > 1 ? $groupedBody($count) : $singleBody;
    }

    public function withinCooldown(string $key, int $seconds): bool
    {
        $cacheKey = "push_cooldown:{$key}";
        if (Cache::has($cacheKey)) {
            return true;
        }

        Cache::put($cacheKey, true, $seconds);

        return false;
    }
}
