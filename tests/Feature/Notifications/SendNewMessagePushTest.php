<?php

namespace Tests\Feature\Notifications;

use App\Events\CustomerMessageReceived;
use App\Listeners\SendNewMessagePush;
use App\Models\NotificationLog;
use Tests\Concerns\InteractsWithPushSchema;
use Tests\TestCase;

/**
 * B.2 regression coverage: the dedup identity used by SendNewMessagePush
 * must include tenant_id and user_id, not just channel + externalId — see
 * the listener's own docblock for why (externalId, e.g. a WhatsApp phone
 * number, has no cross-tenant uniqueness guarantee).
 */
class SendNewMessagePushTest extends TestCase
{
    use InteractsWithPushSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPushSchema();
    }

    private function bodiesFor(int $userId): array
    {
        return NotificationLog::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->pluck('body')
            ->all();
    }

    public function test_repeated_messages_in_the_same_conversation_group_correctly(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $listener = app(SendNewMessagePush::class);

        $event = new CustomerMessageReceived($tenant->id, 'whatsapp', '8801700000000', 'Rahim');
        $listener->handle($event);
        $listener->handle($event);
        $listener->handle($event);

        $bodies = $this->bodiesFor($user->id);

        $this->assertSame('Rahim: নতুন মেসেজ', $bodies[0]);
        $this->assertSame('Rahim 2টি নতুন মেসেজ পাঠিয়েছেন', $bodies[1]);
        $this->assertSame('Rahim 3টি নতুন মেসেজ পাঠিয়েছেন', $bodies[2]);
    }

    public function test_two_tenants_messaged_by_the_same_phone_number_do_not_share_a_count(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $listener = app(SendNewMessagePush::class);

        // Same externalId (phone number) messaging two different shops.
        $listener->handle(new CustomerMessageReceived($tenantA->id, 'whatsapp', '8801700000000', 'Rahim'));
        $listener->handle(new CustomerMessageReceived($tenantA->id, 'whatsapp', '8801700000000', 'Rahim'));
        $listener->handle(new CustomerMessageReceived($tenantB->id, 'whatsapp', '8801700000000', 'Rahim'));

        // Tenant A: two messages so far -> grouped to "2".
        $this->assertSame(
            ['Rahim: নতুন মেসেজ', 'Rahim 2টি নতুন মেসেজ পাঠিয়েছেন'],
            $this->bodiesFor($userA->id)
        );

        // Tenant B: its first message ever for this number -> must NOT
        // inherit tenant A's count.
        $this->assertSame(['Rahim: নতুন মেসেজ'], $this->bodiesFor($userB->id));
    }

    public function test_two_users_in_the_same_tenant_get_independent_counts(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser($tenant->id);
        $userB = $this->makeUser($tenant->id);
        $listener = app(SendNewMessagePush::class);

        $event = new CustomerMessageReceived($tenant->id, 'messenger', 'psid-123', 'Apo');

        // Two separate customer messages, each fanned out to both staff.
        $listener->handle($event);
        $listener->handle($event);

        // Both users were notified about both messages (no one suppressed
        // the other's notification)...
        $bodiesA = $this->bodiesFor($userA->id);
        $bodiesB = $this->bodiesFor($userB->id);
        $this->assertCount(2, $bodiesA);
        $this->assertCount(2, $bodiesB);

        // ...and each user's own grouping counted independently, arriving
        // at the same numbers only because both received the same number
        // of events — not because they share one counter.
        $this->assertSame(['Apo: নতুন মেসেজ', 'Apo 2টি নতুন মেসেজ পাঠিয়েছেন'], $bodiesA);
        $this->assertSame(['Apo: নতুন মেসেজ', 'Apo 2টি নতুন মেসেজ পাঠিয়েছেন'], $bodiesB);
    }

    public function test_inactive_users_are_never_notified(): void
    {
        $tenant = $this->makeTenant();
        $activeUser = $this->makeUser($tenant->id);
        $inactiveUser = $this->makeUser($tenant->id, ['is_active' => 0]);
        $listener = app(SendNewMessagePush::class);

        $listener->handle(new CustomerMessageReceived($tenant->id, 'messenger', 'psid-456', 'Apo'));

        $this->assertCount(1, $this->bodiesFor($activeUser->id));
        $this->assertCount(0, $this->bodiesFor($inactiveUser->id));
    }
}
