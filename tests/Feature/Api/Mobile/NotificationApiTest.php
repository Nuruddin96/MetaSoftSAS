<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\NotificationLog;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * Covers Api\Mobile\NotificationController — backed by the real
 * NotificationLog model (table `notifications`, database/sql/chunk31.sql),
 * written by WebPushService::sendToUser() on real business events.
 */
class NotificationApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! \Illuminate\Support\Facades\Schema::hasTable('notifications')) {
            \Illuminate\Support\Facades\Schema::create('notifications', function ($table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('category', 30);
                $table->string('title', 150);
                $table->string('body', 255);
                $table->string('url')->nullable();
                $table->string('tag', 150)->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function makeLog(int $tenantId, int $userId, array $attrs = []): NotificationLog
    {
        app()->instance('currentTenant', \App\Models\Tenant::find($tenantId));
        $log = NotificationLog::create(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'category' => 'orders',
            'title' => 'নতুন অর্ডার',
            'body' => 'একটি নতুন অর্ডার এসেছে',
        ], $attrs));
        app()->forgetInstance('currentTenant');

        return $log;
    }

    public function test_index_returns_only_the_current_users_notifications_with_unread_count(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $otherUser = $this->makeUser($tenant->id);
        $this->makeLog($tenant->id, $user->id, ['title' => 'Mine']);
        $this->makeLog($tenant->id, $user->id, ['title' => 'Also mine', 'read_at' => now()]);
        $this->makeLog($tenant->id, $otherUser->id, ['title' => 'Not mine']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/v1/notifications')->assertOk();
        $response->assertJsonStructure(['data' => [['id', 'type', 'title', 'body', 'is_read']], 'meta' => ['unread_count']]);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Mine'));
        $this->assertFalse($titles->contains('Not mine'));
        $this->assertSame(1, $response->json('meta.unread_count'));
    }

    public function test_mark_seen_with_specific_ids_only_marks_those(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $a = $this->makeLog($tenant->id, $user->id, ['title' => 'A']);
        $b = $this->makeLog($tenant->id, $user->id, ['title' => 'B']);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/seen', ['ids' => [$a->id]])->assertOk();

        $this->assertNotNull($a->fresh()->read_at);
        $this->assertNull($b->fresh()->read_at);
    }

    public function test_mark_seen_all_marks_every_unread_notification(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $a = $this->makeLog($tenant->id, $user->id, ['title' => 'A']);
        $b = $this->makeLog($tenant->id, $user->id, ['title' => 'B']);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/seen', ['all' => true])->assertOk();

        $this->assertNotNull($a->fresh()->read_at);
        $this->assertNotNull($b->fresh()->read_at);
    }

    public function test_mark_seen_never_touches_another_users_notification(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $otherUser = $this->makeUser($tenant->id);
        $foreign = $this->makeLog($tenant->id, $otherUser->id, ['title' => 'Not mine']);

        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/seen', ['ids' => [$foreign->id]])->assertOk();

        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/mobile/v1/notifications')->assertUnauthorized();
    }
}
