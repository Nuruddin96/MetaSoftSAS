<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSetting;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

class RemoteSupportControllerTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function makeDevice(int $tenantId, int $userId, array $attrs = []): MobileDevice
    {
        return MobileDevice::create(array_merge([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'device_uuid' => 'dev-'.uniqid(),
            'status' => 'pending_verification',
            'verification_code' => 'ABC123',
        ], $attrs));
    }

    public function test_guest_is_redirected_away_from_the_console(): void
    {
        $tenant = $this->makeTenant();

        $this->get(route('super.remote-support.show', $tenant))->assertRedirect();
    }

    public function test_super_admin_can_enable_remote_support_for_a_tenant(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.toggle', $tenant), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('remote_support_settings', ['tenant_id' => $tenant->id, 'enabled' => 1]);
        $this->assertTrue($tenant->fresh()->hasRemoteSupportEnabled());
    }

    public function test_super_admin_can_disable_remote_support_after_enabling(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.toggle', $tenant), ['enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse($tenant->fresh()->hasRemoteSupportEnabled());
    }

    public function test_approving_a_device_requires_the_correct_verification_code(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['verification_code' => 'WXYZ99']);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.approve', [$tenant, $device]), ['verification_code' => 'WRONG1'])
            ->assertSessionHasErrors('verification_code');

        $this->assertSame('pending_verification', $device->fresh()->status);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.approve', [$tenant, $device]), ['verification_code' => 'WXYZ99'])
            ->assertRedirect();

        $fresh = $device->fresh();
        $this->assertSame('off', $fresh->status);
        $this->assertTrue((bool) $fresh->remote_support_enabled);
        $this->assertDatabaseHas('device_events', ['mobile_device_id' => $device->id, 'event_type' => 'device_approved']);
    }

    public function test_super_admin_from_another_tenant_route_cannot_approve_a_different_tenants_device(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $device = $this->makeDevice($tenantB->id, $userB->id, ['verification_code' => 'CODE12']);

        // Device belongs to tenant B but the route is scoped to tenant A —
        // must 404, never leak/act on another tenant's device (tenant
        // isolation, see RemoteSupportController's docblock).
        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.approve', [$tenantA, $device]), ['verification_code' => 'CODE12'])
            ->assertNotFound();

        $this->assertSame('pending_verification', $device->fresh()->status);
    }

    public function test_revoking_a_device_ends_any_open_session_and_deletes_its_credential(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $tokenId = $user->createToken('device:x', ['device:heartbeat'])->accessToken->id;
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
            'credential_token_id' => $tokenId,
        ]);
        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'tok-abc', 'started_at' => now(), 'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.revoke', [$tenant, $device]), ['reason' => 'lost phone'])
            ->assertRedirect();

        $fresh = $device->fresh();
        $this->assertSame('revoked', $fresh->status);
        $this->assertFalse((bool) $fresh->remote_support_enabled);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->assertDatabaseHas('remote_support_sessions', ['mobile_device_id' => $device->id, 'status' => 'ended']);
    }

    public function test_starting_a_session_fails_when_device_is_not_ready(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_not_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertStatus(409);

        $this->assertDatabaseCount('remote_support_sessions', 0);
    }

    public function test_starting_a_session_fails_when_tenant_level_remote_support_is_disabled(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        // No RemoteSupportSetting row at all == disabled by default.
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), [])
            ->assertStatus(409);
    }

    public function test_super_admin_can_start_a_session_on_a_ready_eligible_device(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.session.start', [$tenant, $device]), ['include_microphone' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('remote_support_sessions', [
            'mobile_device_id' => $device->id, 'status' => 'active', 'include_microphone' => 1,
        ]);
        $this->assertDatabaseHas('device_events', ['mobile_device_id' => $device->id, 'event_type' => 'session_started']);
    }

    public function test_a_device_stuck_offline_is_not_eligible_even_if_status_column_says_on_ready(): void
    {
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'status' => 'on_ready',
            'remote_support_enabled' => true,
            'last_seen_at' => now()->subMinutes(10), // stale heartbeat
        ]);

        $this->assertSame('offline', $device->liveStatus());
        $this->assertFalse($device->isEligibleForSession());
    }
}
