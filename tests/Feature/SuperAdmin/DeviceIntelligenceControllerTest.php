<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\DeviceAppUsageDaily;
use App\Models\DeviceIntelligenceFeatureState;
use App\Models\DeviceIntelligenceSetting;
use App\Models\DeviceNotification;
use App\Models\MobileDevice;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithDeviceIntelligenceSchema;
use Tests\TestCase;

class DeviceIntelligenceControllerTest extends TestCase
{
    use InteractsWithDeviceIntelligenceSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDeviceIntelligenceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function makeDevice(int $tenantId, int $userId, array $attrs = []): MobileDevice
    {
        return MobileDevice::create(array_merge([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'device_uuid' => 'dev-'.uniqid(), 'status' => 'on_ready',
        ], $attrs));
    }

    public function test_guest_is_redirected_away_from_the_console(): void
    {
        $tenant = $this->makeTenant();

        $this->get(route('super.device-intelligence.show', $tenant))->assertRedirect();
    }

    public function test_the_menu_item_is_visible_to_a_logged_in_super_admin(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.device-intelligence.index'));

        $response->assertOk();
        $response->assertSee('ডিভাইস ইন্টেলিজেন্স');
        $response->assertSee(route('super.device-intelligence.index'), escape: false);
    }

    public function test_super_admin_can_toggle_device_intelligence_for_a_tenant(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.device-intelligence.toggle', $tenant), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) DeviceIntelligenceSetting::where('tenant_id', $tenant->id)->first()->enabled);
    }

    /** Toggling Device Intelligence must never touch Remote Support's own setting. */
    public function test_toggling_device_intelligence_does_not_affect_remote_support_setting(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        \App\Models\RemoteSupportSetting::create(['tenant_id' => $tenant->id, 'enabled' => true]);

        $this->actingAs($admin, 'super_admin')
            ->post(route('super.device-intelligence.toggle', $tenant), ['enabled' => '1']);

        $this->assertTrue((bool) \App\Models\RemoteSupportSetting::where('tenant_id', $tenant->id)->first()->enabled);
    }

    public function test_device_overview_tab_shows_activation_badges(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, ['device_model' => 'Pixel 8']);
        DeviceIntelligenceFeatureState::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'feature' => 'notification_monitoring',
            'app_consent_status' => 'enabled', 'android_access' => ['notification_listener' => 'granted'], 'activation_status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.device-intelligence.devices.show', [$tenant, $device]));

        $response->assertOk();
        $response->assertSee('Pixel 8');
        $response->assertSee('সক্রিয়');
    }

    public function test_notifications_tab_shows_captured_notifications_with_app_and_sender(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k1',
            'package_name' => 'com.whatsapp', 'app_name' => 'WhatsApp', 'sender' => 'Rahim',
            'body' => 'ভাই কালকে আসবেন?', 'posted_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab=notifications');

        $response->assertOk();
        $response->assertSee('WhatsApp');
        $response->assertSee('Rahim');
        $response->assertSee('ভাই কালকে আসবেন?');
    }

    public function test_notifications_tab_filters_by_named_category(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k1',
            'package_name' => 'com.whatsapp', 'app_name' => 'WhatsApp', 'sender' => 'Rahim', 'posted_at' => now(),
        ]);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k2',
            'package_name' => 'com.facebook.orca', 'app_name' => 'Messenger', 'sender' => 'Tanjin', 'posted_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab=notifications&category=whatsapp');

        $response->assertOk();
        $response->assertSee('Rahim');
        $response->assertDontSee('Tanjin');
    }

    /** The "Other" category must catch every package NOT in the named allowlist (e.g. a plain SMS app) — never silently drop it. */
    public function test_notifications_tab_other_category_catches_unlisted_packages(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k1',
            'package_name' => 'com.whatsapp', 'app_name' => 'WhatsApp', 'sender' => 'Rahim', 'posted_at' => now(),
        ]);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k2',
            'package_name' => 'com.google.android.apps.messaging', 'app_name' => 'Messages', 'sender' => 'SMS Sender', 'posted_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab=notifications&category=other');

        $response->assertOk();
        $response->assertSee('SMS Sender');
        $response->assertDontSee('Rahim');
    }

    public function test_notifications_tab_today_preset_excludes_older_notifications(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k1',
            'package_name' => 'com.whatsapp', 'sender' => 'Today Sender', 'posted_at' => now(),
        ]);
        DeviceNotification::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'client_notification_key' => 'k2',
            'package_name' => 'com.whatsapp', 'sender' => 'Old Sender', 'posted_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab=notifications&range=today');

        $response->assertOk();
        $response->assertSee('Today Sender');
        $response->assertDontSee('Old Sender');
    }

    public function test_notifications_tab_shows_the_access_consent_and_feature_status_strip(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);
        DeviceIntelligenceFeatureState::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'feature' => 'notification_monitoring',
            'app_consent_status' => 'enabled', 'android_access' => ['notification_listener' => 'denied'],
            'activation_status' => 'waiting_for_android_access',
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab=notifications');

        $response->assertOk();
        $response->assertSee('Notification Access');
        $response->assertSee('App Consent');
        $response->assertSee('Enabled');
        $response->assertSee('অ্যান্ড্রয়েড অনুমতির অপেক্ষায়');
    }

    public function test_usage_tab_shows_today_seven_day_and_thirty_day_totals(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id);
        DeviceAppUsageDaily::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'package_name' => 'com.whatsapp',
            'app_name' => 'WhatsApp', 'usage_date' => now()->toDateString(), 'duration_seconds' => 3600,
        ]);
        DeviceAppUsageDaily::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'package_name' => 'com.whatsapp',
            'app_name' => 'WhatsApp', 'usage_date' => now()->subDays(3)->toDateString(), 'duration_seconds' => 1800,
        ]);

        $response = $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab=usage');

        $response->assertOk();
        $response->assertSee('WhatsApp');
        $response->assertSee('1h 0m'); // today
    }

    /**
     * Regression guard: telemetry_synced_at/last_screen_active_at were
     * missing from MobileDevice's $casts, so any populated value would
     * throw "Call to a member function diffForHumans() on string" the
     * moment a telemetry-showing tab rendered — caught here by actually
     * populating both columns (not leaving them null, which would have
     * let the bug pass silently) and asserting each new tab still loads.
     */
    public function test_new_telemetry_tabs_render_with_populated_telemetry_without_error(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        $device = $this->makeDevice($tenant->id, $user->id, [
            'battery_pct' => 42, 'charging' => false, 'battery_saver' => true,
            'screen_on' => true, 'last_screen_active_at' => now()->subMinutes(5),
            'storage_total_bytes' => 64_000_000_000, 'storage_free_bytes' => 12_000_000_000,
            'ram_total_bytes' => 4_000_000_000, 'ram_available_bytes' => 900_000_000,
            'network_type' => 'wifi', 'vpn_active' => false,
            'device_uptime_seconds' => 3661, 'telemetry_synced_at' => now()->subMinutes(2),
        ]);
        DeviceIntelligenceFeatureState::create([
            'tenant_id' => $tenant->id, 'mobile_device_id' => $device->id, 'feature' => 'notification_monitoring',
            'app_consent_status' => 'enabled', 'android_access' => ['notification_listener' => 'granted'],
            'activation_status' => 'active', 'state_observed_at' => now(), 'access_synced_at' => now(), 'last_active_at' => now(),
        ]);

        foreach (['timeline', 'battery', 'storage', 'network', 'health', 'location', 'diagnostics'] as $tab) {
            $response = $this->actingAs($admin, 'super_admin')
                ->get(route('super.device-intelligence.devices.show', [$tenant, $device]).'?tab='.$tab);
            $response->assertOk();
        }
    }

    /** Tenant isolation: Tenant A's admin cannot reach Tenant B's device via this route. */
    public function test_a_device_belonging_to_another_tenant_cannot_be_reached_through_this_tenants_route(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $deviceB = $this->makeDevice($tenantB->id, $userB->id);

        $this->actingAs($admin, 'super_admin')
            ->get(route('super.device-intelligence.devices.show', [$tenantA, $deviceB]))
            ->assertNotFound();
    }

    public function test_show_page_never_leaks_another_tenants_device(): void
    {
        $admin = $this->makeSuperAdmin();
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $this->makeDevice($tenantB->id, $userB->id, ['device_model' => 'Tenant B Device']);

        $response = $this->actingAs($admin, 'super_admin')->get(route('super.device-intelligence.show', $tenantA));

        $response->assertOk();
        $response->assertDontSee('Tenant B Device');
    }
}
