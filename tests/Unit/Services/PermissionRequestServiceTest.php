<?php

namespace Tests\Unit\Services;

use App\Models\MobileDevice;
use App\Models\PermissionRequest;
use App\Services\PermissionRequestService;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithDeviceIntelligenceSchema;
use Tests\TestCase;

/**
 * Uses InteractsWithDeviceIntelligenceSchema (not just
 * InteractsWithRemoteSupportSchema) even though most of these tests are
 * pure Remote Support territory — PermissionRequestService::panelFor()
 * reads device_intelligence_feature_states for the `location` capability,
 * so that table must exist for the full service to be exercisable.
 */
class PermissionRequestServiceTest extends TestCase
{
    use InteractsWithDeviceIntelligenceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeviceIntelligenceSchema();
        Config::set('permission_requests.ttl_minutes', 15);
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

    public function test_create_starts_a_new_sent_attempt_with_an_expiry(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $request = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);

        $this->assertSame(PermissionRequest::STATUS_SENT, $request->status);
        $this->assertNotNull($request->sent_at);
        $this->assertNotNull($request->expires_at);
        $this->assertNull($request->resend_of_id);
        $this->assertDatabaseHas('device_events', [
            'mobile_device_id' => $device->id,
            'event_type' => 'permission_request_sent',
        ]);
    }

    public function test_create_blocks_a_duplicate_simultaneous_request_for_the_same_capability(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $service->create($device, PermissionRequest::CAPABILITY_PHOTOS, $admin);

        $this->expectException(HttpException::class);
        $service->create($device, PermissionRequest::CAPABILITY_PHOTOS, $admin);
    }

    public function test_create_does_not_block_a_different_capability(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $service->create($device, PermissionRequest::CAPABILITY_PHOTOS, $admin);
        $second = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);

        $this->assertSame(PermissionRequest::STATUS_SENT, $second->status);
    }

    public function test_resolve_maps_granted_to_allowed_and_stamps_resolved_at(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);
        $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);

        $resolved = $service->resolve($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, 'granted');

        $this->assertSame(PermissionRequest::STATUS_ALLOWED, $resolved->status);
        $this->assertSame('granted', $resolved->resolved_status);
        $this->assertNotNull($resolved->resolved_at);
    }

    public function test_resolve_maps_permanent_denied_to_denied_and_denied_retryable_stays_distinct(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $resolved = $service->resolve($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, 'denied_retryable');

        $this->assertSame(PermissionRequest::STATUS_DENIED, $resolved->status);
        $this->assertSame('denied_retryable', $resolved->resolved_status);
        $this->assertTrue($resolved->isRetryableTerminal());
    }

    public function test_resolve_maps_not_supported_to_failed(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);
        $service->create($device, PermissionRequest::CAPABILITY_PHOTOS, $admin);

        $resolved = $service->resolve($device, PermissionRequest::CAPABILITY_PHOTOS, 'not_supported');

        $this->assertSame(PermissionRequest::STATUS_FAILED, $resolved->status);
    }

    public function test_resolve_is_a_noop_when_there_is_no_open_attempt(): void
    {
        $device = $this->makeDevice();
        $service = app(PermissionRequestService::class);

        $result = $service->resolve($device, PermissionRequest::CAPABILITY_CAMERA, 'active');

        $this->assertNull($result);
        $this->assertDatabaseCount('permission_requests', 0);
    }

    public function test_mark_delivered_advances_sent_to_delivered_and_is_idempotent(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);
        $request = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);

        $service->markDelivered($device, PermissionRequest::CAPABILITY_NOTIFICATIONS);
        $service->markDelivered($device, PermissionRequest::CAPABILITY_NOTIFICATIONS);

        $this->assertSame(PermissionRequest::STATUS_DELIVERED, $request->fresh()->status);
        $this->assertDatabaseCount('device_events', 2); // sent + delivered, not a third from the second call
    }

    public function test_mark_prompt_shown_advances_from_delivered(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);
        $request = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $service->markDelivered($device, PermissionRequest::CAPABILITY_NOTIFICATIONS);

        $service->markPromptShown($device, PermissionRequest::CAPABILITY_NOTIFICATIONS);

        $this->assertSame(PermissionRequest::STATUS_PROMPT_SHOWN, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->prompt_shown_at);
    }

    public function test_expire_stale_flips_past_due_open_requests_to_expired_and_leaves_fresh_ones_alone(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $stale = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $stale->expires_at = now()->subMinute();
        $stale->save();

        $fresh = $service->create($device, PermissionRequest::CAPABILITY_PHOTOS, $admin);

        $count = $service->expireStale();

        $this->assertSame(1, $count);
        $this->assertSame(PermissionRequest::STATUS_EXPIRED, $stale->fresh()->status);
        $this->assertSame(PermissionRequest::STATUS_SENT, $fresh->fresh()->status);
    }

    public function test_resend_after_expiry_creates_a_new_row_pointing_at_the_old_one_without_deleting_it(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);

        $first = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $first->expires_at = now()->subMinute();
        $first->save();

        $second = $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);

        $this->assertSame($first->id, $second->resend_of_id);
        $this->assertSame(PermissionRequest::STATUS_EXPIRED, $first->fresh()->status);
        $this->assertDatabaseCount('permission_requests', 2);
    }

    public function test_panel_for_reports_already_allowed_as_not_resendable(): void
    {
        $device = $this->makeDevice();
        $device->android_access = ['notifications' => MobileDevice::ACCESS_GRANTED];
        $device->save();
        $service = app(PermissionRequestService::class);

        $panel = $service->panelFor($device);

        $this->assertFalse($panel[PermissionRequest::CAPABILITY_NOTIFICATIONS]['can_resend']);
        $this->assertSame('already_allowed', $panel[PermissionRequest::CAPABILITY_NOTIFICATIONS]['resend_blocked_reason']);
    }

    public function test_panel_for_reports_permanently_denied_as_settings_required_not_resendable(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);
        $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $service->resolve($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, 'denied');

        $panel = $service->panelFor($device);

        $this->assertFalse($panel[PermissionRequest::CAPABILITY_NOTIFICATIONS]['can_resend']);
        $this->assertSame('settings_required', $panel[PermissionRequest::CAPABILITY_NOTIFICATIONS]['resend_blocked_reason']);
    }

    public function test_panel_for_allows_resend_after_a_retryable_denial(): void
    {
        $device = $this->makeDevice();
        $admin = $this->makeSuperAdmin();
        $service = app(PermissionRequestService::class);
        $service->create($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, $admin);
        $service->resolve($device, PermissionRequest::CAPABILITY_NOTIFICATIONS, 'denied_retryable');

        $panel = $service->panelFor($device);

        $this->assertTrue($panel[PermissionRequest::CAPABILITY_NOTIFICATIONS]['can_resend']);
    }
}
