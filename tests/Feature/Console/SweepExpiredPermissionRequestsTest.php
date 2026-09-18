<?php

namespace Tests\Feature\Console;

use App\Models\MobileDevice;
use App\Models\PermissionRequest;
use App\Services\PermissionRequestService;
use Tests\Concerns\InteractsWithRemoteSupportSchema;
use Tests\TestCase;

/** Same shape as SweepStaleRemoteSupportSessionsTest — see that test for the sibling command's own coverage. */
class SweepExpiredPermissionRequestsTest extends TestCase
{
    use InteractsWithRemoteSupportSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteSupportSchema();
    }

    public function test_command_expires_stale_requests_and_leaves_fresh_ones_pending(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => 'uuid-1',
            'status' => 'on_ready', 'remote_support_enabled' => true,
        ]);
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $stale = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $stale->expires_at = now()->subMinute();
        $stale->save();

        $fresh = $service->create($device, PermissionRequest::CAPABILITY_PHOTOS, $admin);

        $this->artisan('permission-requests:sweep-expired')
            ->expectsOutputToContain('Expired 1 stale permission request(s).')
            ->assertExitCode(0);

        $this->assertSame(PermissionRequest::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertSame(PermissionRequest::STATUS_SENT, $fresh->fresh()->status);
    }
}
