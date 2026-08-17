<?php

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationPreferenceService;
use Tests\Concerns\InteractsWithPushSchema;
use Tests\TestCase;

class NotificationPreferenceServiceTest extends TestCase
{
    use InteractsWithPushSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPushSchema();
    }

    public function test_every_category_defaults_to_enabled_with_no_stored_row(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new NotificationPreferenceService;

        foreach (array_keys(NotificationPreferenceService::CATEGORIES) as $category) {
            $this->assertTrue($service->isEnabled($user, $category), "{$category} should default to enabled");
        }
    }

    public function test_security_is_always_enabled_and_cannot_be_disabled(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new NotificationPreferenceService;

        $service->setEnabled($user, NotificationPreferenceService::SECURITY_CATEGORY, false);

        $this->assertTrue(
            $service->isEnabled($user, NotificationPreferenceService::SECURITY_CATEGORY),
            'security must stay enabled even after an attempt to disable it'
        );
        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $user->id,
            'category' => 'security',
        ]);
    }

    public function test_setEnabled_persists_an_opt_out_and_isEnabled_reflects_it(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $service = new NotificationPreferenceService;

        $service->setEnabled($user, 'stock', false);

        $this->assertFalse($service->isEnabled($user, 'stock'));
        // Every other category is untouched by disabling one.
        $this->assertTrue($service->isEnabled($user, 'orders'));
    }

    public function test_preferences_for_one_user_do_not_leak_to_another(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser($tenant->id);
        $userB = $this->makeUser($tenant->id);
        $service = new NotificationPreferenceService;

        $service->setEnabled($userA, 'messages', false);

        $this->assertFalse($service->isEnabled($userA, 'messages'));
        $this->assertTrue($service->isEnabled($userB, 'messages'), 'user B must keep the default, unaffected by user A\'s opt-out');
    }
}
