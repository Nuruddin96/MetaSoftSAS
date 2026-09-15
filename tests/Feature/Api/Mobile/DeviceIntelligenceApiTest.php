<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\DeviceAppUsageDaily;
use App\Models\DeviceIntelligenceFeatureState;
use App\Models\DeviceIntelligenceSetting;
use App\Models\DeviceNotification;
use App\Models\MobileDevice;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithDeviceIntelligenceSchema;
use Tests\TestCase;

class DeviceIntelligenceApiTest extends TestCase
{
    use InteractsWithDeviceIntelligenceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeviceIntelligenceSchema();
    }

    private function makeDeviceWithToken(): array
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $uuid = 'uuid-'.uniqid();
        $token = $user->createToken('device:'.$uuid, ['device:heartbeat']);
        $device = MobileDevice::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'device_uuid' => $uuid,
            'status' => 'on_ready', 'remote_support_enabled' => false,
            'credential_token_id' => $token->accessToken->id,
        ]);

        return [$tenant, $user, $device, $token->plainTextToken];
    }

    // --- status / tenant gate -------------------------------------------

    public function test_status_reflects_tenant_level_enablement(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/devices/intelligence/status')
            ->assertOk()->assertJsonPath('tenant_device_intelligence_enabled', false);
    }

    public function test_status_reports_true_once_the_tenant_setting_is_enabled(): void
    {
        // A fresh request cycle (rather than reusing the same
        // Sanctum::actingAs()'d user across two calls in one test) —
        // Eloquent caches the `tenant`/`deviceIntelligenceSetting`
        // relations on a resolved model instance, so creating the
        // setting row AFTER the auth user is already resolved can read
        // back stale null through the same cached instance.
        $tenant = $this->makeTenant();
        DeviceIntelligenceSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/mobile/v1/devices/intelligence/status')
            ->assertOk()->assertJsonPath('tenant_device_intelligence_enabled', true);
    }

    // --- auth -------------------------------------------------------------

    public function test_feature_sync_requires_the_device_credential_not_the_login_token(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/v1/devices/intelligence/feature-sync', ['feature' => 'app_usage'])
            ->assertStatus(403);
    }

    public function test_feature_sync_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/mobile/v1/devices/intelligence/feature-sync', ['feature' => 'app_usage'])
            ->assertStatus(401);
    }

    // --- tenant/device authorization --------------------------------------

    public function test_sync_only_ever_updates_the_device_bound_to_the_presented_token(): void
    {
        [, , $deviceA, $tokenA] = $this->makeDeviceWithToken();
        [, , $deviceB] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'app_usage',
                'app_consent_status' => 'enabled',
            ])->assertOk();

        $this->assertSame(1, DeviceIntelligenceFeatureState::where('mobile_device_id', $deviceA->id)->count());
        $this->assertSame(0, DeviceIntelligenceFeatureState::where('mobile_device_id', $deviceB->id)->count());
    }

    // --- the A/B/C/D activation matrix, per feature -----------------------

    public function test_a_android_access_denied_consent_disabled_is_inactive(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'app_usage', 'app_consent_status' => 'disabled',
                'android_access' => ['usage_access' => 'denied'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'inactive');
    }

    public function test_b_android_access_denied_consent_enabled_is_waiting_for_android_access(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'notification_monitoring', 'app_consent_status' => 'enabled',
                'android_access' => ['notification_listener' => 'denied'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'waiting_for_android_access');
    }

    public function test_c_android_access_granted_consent_disabled_is_disabled_by_tenant(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'notification_monitoring', 'app_consent_status' => 'disabled',
                'android_access' => ['notification_listener' => 'granted'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'disabled_by_tenant');
    }

    public function test_d_android_access_granted_consent_enabled_is_active(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'notification_monitoring', 'app_consent_status' => 'enabled',
                'android_access' => ['notification_listener' => 'granted'],
            ])->assertOk();

        $response->assertJsonPath('activation_status', 'active');
        $this->assertNotNull(DeviceIntelligenceFeatureState::where('mobile_device_id', $device->id)->first()->last_active_at);
    }

    public function test_device_health_has_no_required_access_and_activates_on_consent_alone(): void
    {
        [, , , $token] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'device_health', 'app_consent_status' => 'enabled',
            ])->assertOk()->assertJsonPath('activation_status', 'active');
    }

    // --- stale/out-of-order hardening (same mechanism as Remote Support) --

    public function test_older_state_is_rejected_and_does_not_regress_current_state(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $t1 = now()->subMinutes(5);
        $t2 = now();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'app_usage', 'app_consent_status' => 'disabled', 'observed_at' => $t2->toIso8601String(),
            ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/feature-sync', [
                'feature' => 'app_usage', 'app_consent_status' => 'enabled', 'observed_at' => $t1->toIso8601String(),
            ])->assertOk()->assertJsonPath('app_consent_status', 'disabled');

        $this->assertSame('disabled', DeviceIntelligenceFeatureState::where('mobile_device_id', $device->id)->where('feature', 'app_usage')->first()->app_consent_status);
    }

    public function test_a_duplicate_identical_sync_does_not_log_a_second_device_event(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $payload = ['feature' => 'app_usage', 'app_consent_status' => 'enabled', 'android_access' => ['usage_access' => 'granted']];

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/intelligence/feature-sync', $payload)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mobile/v1/devices/intelligence/feature-sync', $payload)->assertOk();

        $this->assertSame(
            1,
            \App\Models\DeviceEvent::where('mobile_device_id', $device->id)->where('event_type', 'device_intelligence_feature_synced')->count(),
        );
    }

    // --- telemetry ----------------------------------------------------------

    public function test_telemetry_sync_updates_the_device_row(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/telemetry-sync', [
                'battery_pct' => 42, 'screen_on' => true, 'storage_total_bytes' => 128000000000,
                'storage_free_bytes' => 32000000000, 'wifi_connected' => true,
            ])->assertOk();

        $fresh = $device->fresh();
        $this->assertSame(42, $fresh->battery_pct);
        $this->assertTrue((bool) $fresh->screen_on);
        $this->assertNotNull($fresh->telemetry_synced_at);
    }

    // --- notifications: idempotent batch upload -----------------------------

    public function test_notification_sync_stores_a_batch_and_is_idempotent_on_retry(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $payload = ['notifications' => [
            [
                'client_notification_key' => 'key-1', 'package_name' => 'com.whatsapp', 'app_name' => 'WhatsApp',
                'sender' => 'Rahim', 'body' => 'ভাই কালকে আসবেন?', 'posted_at' => now()->toIso8601String(),
            ],
            [
                'client_notification_key' => 'key-2', 'package_name' => 'com.facebook.orca', 'app_name' => 'Messenger',
                'sender' => 'Tanjin', 'body' => 'Hi', 'posted_at' => now()->toIso8601String(),
            ],
        ]];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/notifications/sync', $payload)
            ->assertOk()->assertJsonPath('inserted', 2);

        // Retry of the EXACT same batch (a network-level duplicate) must
        // insert nothing new.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/notifications/sync', $payload)
            ->assertOk()->assertJsonPath('inserted', 0);

        $this->assertSame(2, DeviceNotification::where('mobile_device_id', $device->id)->count());
    }

    public function test_a_removed_notification_updates_removed_at_instead_of_inserting_a_new_row(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/notifications/sync', ['notifications' => [
                ['client_notification_key' => 'key-1', 'package_name' => 'com.whatsapp', 'posted_at' => now()->toIso8601String()],
            ]])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/notifications/sync', ['notifications' => [
                ['client_notification_key' => 'key-1', 'package_name' => 'com.whatsapp', 'posted_at' => now()->toIso8601String(), 'removed' => true],
            ]])->assertOk();

        $this->assertSame(1, DeviceNotification::where('mobile_device_id', $device->id)->count());
        $this->assertNotNull(DeviceNotification::where('mobile_device_id', $device->id)->first()->removed_at);
    }

    public function test_a_device_cannot_sync_notifications_into_another_tenants_row(): void
    {
        [, , $deviceA, $tokenA] = $this->makeDeviceWithToken();
        [, , $deviceB] = $this->makeDeviceWithToken();

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/mobile/v1/devices/intelligence/notifications/sync', ['notifications' => [
                ['client_notification_key' => 'key-1', 'package_name' => 'com.whatsapp', 'posted_at' => now()->toIso8601String()],
            ]])->assertOk();

        $this->assertSame(1, DeviceNotification::where('mobile_device_id', $deviceA->id)->count());
        $this->assertSame(0, DeviceNotification::where('mobile_device_id', $deviceB->id)->count());
    }

    // --- app usage: idempotent upsert ----------------------------------------

    public function test_usage_sync_upserts_the_same_day_instead_of_duplicating_rows(): void
    {
        [, , $device, $token] = $this->makeDeviceWithToken();
        $today = now()->toDateString();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/usage/sync', ['usage' => [
                ['package_name' => 'com.whatsapp', 'app_name' => 'WhatsApp', 'usage_date' => $today, 'duration_seconds' => 300],
            ]])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/mobile/v1/devices/intelligence/usage/sync', ['usage' => [
                ['package_name' => 'com.whatsapp', 'app_name' => 'WhatsApp', 'usage_date' => $today, 'duration_seconds' => 900],
            ]])->assertOk();

        $this->assertSame(1, DeviceAppUsageDaily::where('mobile_device_id', $device->id)->count());
        $this->assertSame(900, DeviceAppUsageDaily::where('mobile_device_id', $device->id)->first()->duration_seconds);
    }
}
