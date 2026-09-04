<?php

namespace Tests\Feature\Console;

use App\Models\MobileDevice;
use App\Models\RemoteSupportSetting;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

/**
 * Covers the independent-of-any-admin-action sweep — see
 * SweepStaleRemoteSupportSessions's docblock. RemoteSupportControllerTest
 * already covers the SAME isExpired()/isLikelyAbandoned() self-heal
 * triggered by a new session start on the SAME device; this proves the
 * scheduled command reaches a stale session even when nobody ever retries
 * that device, and across more than one tenant in a single run.
 */
class SweepStaleRemoteSupportSessionsTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
    }

    protected function makeDevice(int $tenantId, int $userId): MobileDevice
    {
        return MobileDevice::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'device_uuid' => 'dev-'.uniqid(),
            'status' => 'on_ready',
            'remote_support_enabled' => true,
        ]);
    }

    public function test_sweeps_an_expired_session_left_active_on_a_device_nobody_retried(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);

        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'stale-expired', 'started_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(5), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]);

        $this->artisan('remote-support:sweep-stale-sessions')->assertSuccessful();

        $this->assertDatabaseHas('remote_support_sessions', [
            'session_token' => 'stale-expired', 'status' => 'ended', 'end_reason' => 'expired',
        ]);
        $this->assertDatabaseHas('device_events', [
            'mobile_device_id' => $device->id, 'event_type' => 'session_ended', 'actor_type' => 'system',
        ]);
    }

    public function test_sweeps_a_never_connected_session_past_the_grace_window_across_multiple_tenants(): void
    {
        $admin = $this->makeSuperAdmin();

        $tenantA = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenantA->id, 'enabled' => true]);
        $userA = $this->makeUser($tenantA->id);
        $deviceA = $this->makeDevice($tenantA->id, $userA->id);

        $tenantB = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenantB->id, 'enabled' => true]);
        $userB = $this->makeUser($tenantB->id);
        $deviceB = $this->makeDevice($tenantB->id, $userB->id);

        DB::table('remote_support_sessions')->insert([
            [
                'tenant_id' => $tenantA->id, 'mobile_device_id' => $deviceA->id, 'started_by_super_admin_id' => $admin->id,
                'status' => 'active', 'session_token' => 'tenant-a-abandoned', 'started_at' => now()->subMinutes(3),
                'connected_at' => null, 'expires_at' => now()->addMinutes(27),
                'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
            ],
            [
                'tenant_id' => $tenantB->id, 'mobile_device_id' => $deviceB->id, 'started_by_super_admin_id' => $admin->id,
                'status' => 'active', 'session_token' => 'tenant-b-still-live', 'started_at' => now(),
                'connected_at' => now(), 'expires_at' => now()->addMinutes(30),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->artisan('remote-support:sweep-stale-sessions')->assertSuccessful();

        $this->assertDatabaseHas('remote_support_sessions', [
            'session_token' => 'tenant-a-abandoned', 'status' => 'ended', 'end_reason' => 'abandoned_no_connection',
        ]);
        // A genuinely live, already-connected session in a DIFFERENT tenant
        // must survive the sweep untouched — proves the command isn't
        // scoped to (or blocked by) a single tenant, and isn't
        // indiscriminately ending every open session.
        $this->assertDatabaseHas('remote_support_sessions', [
            'session_token' => 'tenant-b-still-live', 'status' => 'active',
        ]);
    }

    public function test_does_not_touch_a_session_still_within_its_grace_window(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);

        DB::table('remote_support_sessions')->insert([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'started_by_super_admin_id' => $admin->id,
            'status' => 'active', 'session_token' => 'fresh', 'started_at' => now(),
            'connected_at' => null, 'expires_at' => now()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('remote-support:sweep-stale-sessions')->assertSuccessful();

        $this->assertDatabaseHas('remote_support_sessions', ['session_token' => 'fresh', 'status' => 'active']);
    }
}
