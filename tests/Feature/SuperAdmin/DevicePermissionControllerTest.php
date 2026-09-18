<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\MobileDevice;
use App\Models\PermissionRequest;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

/**
 * Was previously zero test coverage for this controller (confirmed by
 * grep before this feature was built) — covers both the pre-existing
 * single-slot pending_permission_request behavior (untouched) and the
 * new unified permission_requests row this task added alongside it.
 */
class DevicePermissionControllerTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function makeDevice(): MobileDevice
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        return MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-'.uniqid(),
            'status' => 'on_ready', 'remote_support_enabled' => true,
        ]);
    }

    public function test_requesting_an_unsupported_permission_is_rejected(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'camera']))
            ->assertNotFound();
    }

    public function test_requesting_a_supported_permission_sets_the_single_slot_flag_and_creates_a_unified_request_row(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'notifications']))
            ->assertRedirect();

        $fresh = $device->fresh();
        $this->assertSame('notifications', $fresh->pending_permission_request['permission']);
        $this->assertDatabaseHas('permission_requests', [
            'mobile_device_id' => $device->id,
            'capability' => 'notifications',
            'status' => PermissionRequest::STATUS_SENT,
        ]);
    }

    public function test_a_second_request_for_the_same_permission_while_one_is_open_is_blocked(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'photos']));

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'photos']))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('permission_requests', 1);
    }

    public function test_an_already_granted_permission_is_not_re_requested(): void
    {
        $device = $this->makeDevice();
        $device->android_access = ['notifications' => MobileDevice::ACCESS_GRANTED];
        $device->save();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'notifications']))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('permission_requests', 0);
        $this->assertNull($device->fresh()->pending_permission_request);
    }

    public function test_resend_after_the_first_attempt_expires_creates_a_second_attempt_row(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'notifications']));

        $first = \App\Models\PermissionRequest::first();
        $first->expires_at = now()->subMinute();
        $first->save();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'notifications']))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('permission_requests', 2);
        $second = \App\Models\PermissionRequest::latest('id')->first();
        $this->assertSame($first->id, $second->resend_of_id);
    }

    public function test_revoked_device_cannot_be_requested(): void
    {
        $device = $this->makeDevice();
        $device->status = MobileDevice::STATUS_REVOKED;
        $device->save();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.remote-support.devices.permissions.request', [$device->tenant, $device, 'notifications']))
            ->assertForbidden();
    }
}
