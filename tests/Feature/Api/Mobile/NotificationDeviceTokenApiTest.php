<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\DevicePushToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * FCM push notifications task: `POST/DELETE notifications/device-token`
 * (Api\Mobile\NotificationController::registerDeviceToken/
 * unregisterDeviceToken) — deliberately separate from Remote Support's
 * device-credential-scoped devices/fcm-token, see that method's docblock.
 */
class NotificationDeviceTokenApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasTable('device_push_tokens')) {
            Schema::create('device_push_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('token', 255);
                $table->string('platform', 20)->default('android');
                $table->string('app_version', 30)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->boolean('is_active')->default(1);
                $table->timestamps();
                $table->unique('token');
                $table->index(['user_id', 'is_active']);
            });
        }
    }

    public function test_register_creates_a_token_row_scoped_to_the_authenticated_user(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/device-token', [
            'token' => 'fcm-token-abc',
            'platform' => 'android',
            'app_version' => '1.2.3',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('device_push_tokens', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'token' => 'fcm-token-abc',
            'platform' => 'android',
            'app_version' => '1.2.3',
            'is_active' => 1,
        ]);
    }

    public function test_registering_the_same_token_twice_updates_the_existing_row_not_a_duplicate(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/notifications/device-token', ['token' => 'fcm-token-abc'])->assertOk();
        $this->postJson('/api/mobile/v1/notifications/device-token', ['token' => 'fcm-token-abc'])->assertOk();

        $this->assertSame(1, DevicePushToken::withoutGlobalScopes()->where('token', 'fcm-token-abc')->count());
    }

    public function test_registering_a_token_already_owned_by_another_tenant_reassigns_it(): void
    {
        // Same physical device, previously logged into a different
        // tenant's account — the token must be reassigned, not rejected
        // with a duplicate-key error (see the controller's docblock). Both
        // tenants/users are created up front, before any authenticated
        // request — bind.tenant.token leaves app('currentTenant') bound
        // to the first request's tenant afterwards, which would make a
        // makeUser() call issued in between (wrongly) tenant-scoped.
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        Sanctum::actingAs($userA);
        $this->postJson('/api/mobile/v1/notifications/device-token', ['token' => 'shared-device-token'])->assertOk();

        Sanctum::actingAs($userB);
        $this->postJson('/api/mobile/v1/notifications/device-token', ['token' => 'shared-device-token'])->assertOk();

        $this->assertSame(1, DevicePushToken::withoutGlobalScopes()->where('token', 'shared-device-token')->count());
        $row = DevicePushToken::withoutGlobalScopes()->where('token', 'shared-device-token')->first();
        $this->assertSame($tenantB->id, $row->tenant_id);
        $this->assertSame($userB->id, $row->user_id);
    }

    public function test_unregister_deactivates_only_the_authenticated_users_own_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $otherUser = $this->makeUser($tenant->id);

        DevicePushToken::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'token' => 'mine', 'is_active' => true,
        ]);
        DevicePushToken::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'user_id' => $otherUser->id, 'token' => 'not-mine', 'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/mobile/v1/notifications/device-token', ['token' => 'not-mine'])->assertOk();

        $this->assertTrue((bool) DevicePushToken::withoutGlobalScopes()->where('token', 'not-mine')->first()->is_active);

        $this->deleteJson('/api/mobile/v1/notifications/device-token', ['token' => 'mine'])->assertOk();
        $this->assertFalse((bool) DevicePushToken::withoutGlobalScopes()->where('token', 'mine')->first()->is_active);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/mobile/v1/notifications/device-token', ['token' => 'x'])->assertUnauthorized();
    }
}
