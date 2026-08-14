<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationLog;
use App\Services\Notifications\WebPushService;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithPushSchema;
use Tests\TestCase;

/**
 * B.3 regression coverage: WebPushService must degrade gracefully — no
 * fatal DB error, no broken request — when database/sql/chunk31.sql
 * hasn't been imported yet, matching the same tablesReady() convention
 * every other post-schema.sql feature in this app already follows.
 */
class WebPushServiceTablesReadyTest extends TestCase
{
    use InteractsWithPushSchema;

    public function test_it_safely_no_ops_and_logs_a_warning_when_tables_are_missing(): void
    {
        // tenants/users exist; push_subscriptions/notifications/
        // notification_preferences deliberately do not.
        $this->setUpPushSchema(includeAllTables: false);
        Log::spy();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        app(WebPushService::class)->sendToUser($user, [
            'title' => 'নতুন মেসেজ',
            'body' => 'Rahim: নতুন মেসেজ',
        ], category: 'messages');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'notification tables are not present'));
    }

    public function test_it_records_and_sends_normally_once_tables_exist(): void
    {
        $this->setUpPushSchema();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        app(WebPushService::class)->sendToUser($user, [
            'title' => 'নতুন মেসেজ',
            'body' => 'Rahim: নতুন মেসেজ',
            'tag' => 'msg:1:1:whatsapp:8801700000000',
        ], category: 'messages');

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'category' => 'messages',
            'body' => 'Rahim: নতুন মেসেজ',
        ]);
    }

    public function test_a_disabled_category_still_no_ops_cleanly_once_tables_exist(): void
    {
        $this->setUpPushSchema();

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        app(\App\Services\Notifications\NotificationPreferenceService::class)->setEnabled($user, 'messages', false);

        app(WebPushService::class)->sendToUser($user, [
            'title' => 'নতুন মেসেজ',
            'body' => 'Rahim: নতুন মেসেজ',
        ], category: 'messages');

        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }
}
