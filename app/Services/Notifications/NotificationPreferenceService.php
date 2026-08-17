<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Per-user, per-category push opt-out. 'security' is intentionally not a
 * real toggle — isEnabled() always returns true for it and setEnabled() is
 * a no-op, so a compromised/careless click can never silence "new login" /
 * "password changed" / "suspicious login" alerts. Every other category
 * defaults ON (sensible for a business owner who wants to know about
 * orders/messages/money by default) and can be turned off per user.
 */
class NotificationPreferenceService
{
    public const CATEGORIES = [
        'orders' => ['label' => 'অর্ডার', 'icon' => '🔔'],
        'messages' => ['label' => 'মেসেজ', 'icon' => '💬'],
        'delivery' => ['label' => 'ডেলিভারি', 'icon' => '📦'],
        'stock' => ['label' => 'স্টক', 'icon' => '⚠️'],
        'payments' => ['label' => 'পেমেন্ট', 'icon' => '💳'],
        'subscription' => ['label' => 'সাবস্ক্রিপশন', 'icon' => '📅'],
        'summary' => ['label' => 'দৈনিক সারসংক্ষেপ', 'icon' => '📊'],
        'technical' => ['label' => 'কারিগরি সতর্কতা', 'icon' => '⚙️'],
    ];

    public const SECURITY_CATEGORY = 'security';

    public function isEnabled(User $user, string $category): bool
    {
        if ($category === self::SECURITY_CATEGORY) {
            return true;
        }

        $pref = NotificationPreference::where('user_id', $user->id)
            ->where('category', $category)
            ->first();

        // No stored row = default on (same "absence = default" convention
        // the rest of this app already uses for store_settings toggles).
        return $pref?->enabled ?? true;
    }

    public function setEnabled(User $user, string $category, bool $enabled): void
    {
        if ($category === self::SECURITY_CATEGORY || ! array_key_exists($category, self::CATEGORIES)) {
            return;
        }

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'category' => $category],
            ['enabled' => $enabled]
        );
    }

    /**
     * @return array<string, array{label: string, icon: string, enabled: bool}>
     */
    public function forUser(User $user): array
    {
        $rows = [];
        foreach (self::CATEGORIES as $category => $meta) {
            $rows[$category] = $meta + ['enabled' => $this->isEnabled($user, $category)];
        }

        return $rows;
    }
}
