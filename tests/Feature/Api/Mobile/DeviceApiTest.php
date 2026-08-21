<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSession;
use App\Models\RemoteSupportSetting;
use App\Models\RemoteSupportSignal;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
    }

    public function test_registration_is_rejected_when_tenant_does_not_have_remote_support_enabled(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/devices/register', ['device_uuid' => 'uuid-1'])
            ->assertNotFound();

        $this->assertDatabaseCount('mobile_devices', 0);
    }

    /**
     * The whole point of the "Allow Access" flow (see SetupController on
     * the Dart side): registration itself auto-approves immediately, no
     * verification code, no Super Admin per-device confirmation step —
     * see RemoteSupportService::registerDevice()'s doc comment for why
     * that's still safe. Tenant-level enable (checked above,
     * test_registration_is_rejected_when_tenant_does_not_have_remote_support_enabled)
     * is the real gate.
     */
    public function test_registration_auto_approves_and_issues_a_separate_device_token(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $loginToken = $user->createToken('mobile-app');

        $response = $this->withHeader('Authorization', 'Bearer '.$loginToken->plainTextToken)
            ->postJson('/api/mobile/v1/devices/register', [
                'device_uuid' => 'uuid-1',
                'platform' => 'android',
                'device_model' => 'Pixel 8',
                'os_version' => '15',
                'app_version' => '1.0.0',
            ])->assertCreated();

        $response->assertJsonPath('status', 'off');
        $response->assertJsonMissing(['verification_code']);
        $this->assertNotEmpty($response->json('device_token'));

        $device = MobileDevice::where('device_uuid', 'uuid-1')->first();
        $this->assertSame($tenant->id, $device->tenant_id);
        $this->assertTrue((bool) $device->remote_support_enabled);
        $this->assertNotNull($device->approved_at);
        $this->assertNull($device->approved_by_super_admin_id);
        $this->assertNotNull($device->credential_token_id);
        $this->assertDatabaseHas('device_events', ['mobile_device_id' => $device->id, 'event_type' => 'device_registered_auto_approved']);

        // The device token must be a DIFFERENT credential than the user's
        // own login token (security-model.md §4), not the same row.
        $this->assertNotSame($loginToken->accessToken->id, $device->credential_token_id);
    }

    public function test_re_registering_a_lost_uuid_device_auto_approves_again(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'on_ready', 'remote_support_enabled' => true,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/devices/register', ['device_uuid' => 'uuid-1'])->assertCreated();

        $device = MobileDevice::where('device_uuid', 'uuid-1')->first();
        $this->assertSame('off', $device->status);
        $this->assertTrue((bool) $device->remote_support_enabled);
    }

    /**
     * The one case that must NOT auto-approve: a Super Admin revoking a
     * device is a deliberate security decision and must stay terminal
     * regardless of who re-registers that device_uuid — otherwise
     * removing the code-paste workflow would have quietly made revoke
     * bypassable by the tenant themselves.
     */
    public function test_a_revoked_device_cannot_re_register_itself_back_to_trusted(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'revoked', 'remote_support_enabled' => false,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/devices/register', ['device_uuid' => 'uuid-1'])
            ->assertStatus(403);

        $device = MobileDevice::where('device_uuid', 'uuid-1')->first();
        $this->assertSame('revoked', $device->status);
        $this->assertFalse((bool) $device->remote_support_enabled);
    }

    public function test_heartbeat_requires_the_device_credential_not_the_users_login_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user); // ordinary login token, no device abilities

        $this->postJson('/api/mobile/v1/devices/heartbeat', ['battery_pct' => 80])
            ->assertStatus(403);
    }

    public function test_heartbeat_updates_presence_and_flips_status_to_on_ready_once_preconditions_are_met(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $token = $user->createToken('device:uuid-1', ['device:heartbeat', 'device:signal']);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'off', 'remote_support_enabled' => true,
            'credential_token_id' => $token->accessToken->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/mobile/v1/devices/heartbeat', [
                'battery_pct' => 91,
                'charging' => false,
                'network_type' => 'wifi',
                'foreground_service_running' => true,
                'permissions' => ['notifications' => true, 'battery_optimization_exempt' => true],
            ])->assertOk();

        $response->assertJsonPath('status', 'on_ready');
        $response->assertJsonPath('active_session', null);

        $fresh = $device->fresh();
        $this->assertSame(91, $fresh->battery_pct);
        $this->assertNotNull($fresh->last_seen_at);
    }

    public function test_heartbeat_surfaces_a_pending_session_so_the_device_can_discover_it_without_push(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $token = $user->createToken('device:uuid-1', ['device:heartbeat']);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'credential_token_id' => $token->accessToken->id,
        ]);
        RemoteSupportSession::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => 1,
            'status' => 'active', 'session_token' => 'sess-live', 'include_microphone' => true,
            'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/mobile/v1/devices/heartbeat', ['foreground_service_running' => true])
            ->assertOk()
            ->assertJsonPath('active_session.session_token', 'sess-live')
            ->assertJsonPath('active_session.include_microphone', true);
    }

    public function test_heartbeat_does_not_surface_an_expired_abandoned_session_as_active(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $token = $user->createToken('device:uuid-1', ['device:heartbeat']);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'credential_token_id' => $token->accessToken->id,
        ]);
        RemoteSupportSession::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => 1,
            'status' => 'active', 'session_token' => 'stale', 'started_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(1),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/mobile/v1/devices/heartbeat', ['foreground_service_running' => true])
            ->assertOk()
            ->assertJsonPath('active_session', null);
    }

    public function test_heartbeat_leaves_device_not_ready_when_a_required_permission_is_missing(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $token = $user->createToken('device:uuid-1', ['device:heartbeat']);
        MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'off', 'remote_support_enabled' => true,
            'credential_token_id' => $token->accessToken->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/mobile/v1/devices/heartbeat', [
                'foreground_service_running' => true,
                'permissions' => ['notifications' => true, 'battery_optimization_exempt' => false],
            ])->assertOk()->assertJsonPath('status', 'on_not_ready');
    }

    public function test_revoked_device_cannot_heartbeat(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $token = $user->createToken('device:uuid-1', ['device:heartbeat']);
        MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'revoked', 'credential_token_id' => $token->accessToken->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/mobile/v1/devices/heartbeat', [])
            ->assertStatus(403);
    }

    public function test_a_device_cannot_send_signals_into_another_tenants_session(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $tokenA = $userA->createToken('device:a', ['device:heartbeat', 'device:signal']);
        $deviceA = MobileDevice::create([
            'tenant_id' => $tenantA->id, 'user_id' => $userA->id, 'device_uuid' => 'a',
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'credential_token_id' => $tokenA->accessToken->id,
        ]);

        $tokenB = $userB->createToken('device:b', ['device:heartbeat', 'device:signal']);
        $deviceB = MobileDevice::create([
            'tenant_id' => $tenantB->id, 'user_id' => $userB->id, 'device_uuid' => 'b',
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'credential_token_id' => $tokenB->accessToken->id,
        ]);

        $superAdminId = 1;
        $sessionB = RemoteSupportSession::create([
            'tenant_id' => $tenantB->id, 'mobile_device_id' => $deviceB->id,
            'started_by_super_admin_id' => $superAdminId, 'status' => 'active',
            'session_token' => 'session-b-token', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        // Device A tries to post into device B's session using its own
        // valid device credential — must never succeed (tenant isolation).
        $this->withHeader('Authorization', 'Bearer '.$tokenA->plainTextToken)
            ->postJson('/api/mobile/v1/devices/sessions/session-b-token/signal', [
                'type' => 'offer', 'payload' => '{}',
            ])->assertNotFound();

        $this->assertDatabaseCount('remote_support_signals', 0);
    }

    public function test_device_can_exchange_offer_and_receive_admin_answer_through_the_signal_queue(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $token = $user->createToken('device:x', ['device:heartbeat', 'device:signal']);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'x',
            'status' => 'on_ready', 'remote_support_enabled' => true,
            'credential_token_id' => $token->accessToken->id,
        ]);
        $session = RemoteSupportSession::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id,
            'started_by_super_admin_id' => 1, 'status' => 'active',
            'session_token' => 'sess-xyz', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/mobile/v1/devices/sessions/sess-xyz/signal', ['type' => 'offer', 'payload' => '{"sdp":"..."}'])
            ->assertCreated();

        // Simulate the admin's answer landing in the queue directly (the
        // admin side is SuperAdmin\RemoteSupportController, exercised in
        // its own test, including the connected_at flip this answer
        // triggers) — here we only need the device to poll and see it.
        RemoteSupportSignal::create([
            'tenant_id' => $tenant->id, 'remote_support_session_id' => $session->id,
            'sender' => 'admin', 'type' => 'answer', 'payload' => '{"sdp":"..."}', 'created_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/mobile/v1/devices/sessions/sess-xyz/signal?since=0')
            ->assertOk();

        $this->assertCount(1, $response->json('signals'));
        $this->assertSame('answer', $response->json('signals.0.type'));
    }
}
