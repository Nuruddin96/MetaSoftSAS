<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\DeviceIntelligenceFeatureState;
use App\Models\MobileDevice;
use App\Services\DeviceIntelligence\DeviceIntelligenceService;
use Illuminate\Http\Request;

/**
 * Device Intelligence mobile-agent endpoints — a SEPARATE module from
 * Remote Support's Api\Mobile\DeviceController, reusing only the same
 * device identity/credential (see MobileDevice's docblock: `device:
 * heartbeat` ability, issued once at registration, covers both modules —
 * no duplicate device identity/registration flow, no new Sanctum ability
 * introduced here). Every action resolves the target MobileDevice from
 * the device-credential token itself, never a client-supplied id.
 */
class DeviceIntelligenceController extends Controller
{
    public function __construct(protected DeviceIntelligenceService $service) {}

    /** Lets the app decide, at any point, whether to show the (nav-hidden until enabled) Device Intelligence entry point at all — mirrors DeviceController::status()'s tenant-gate shape exactly, but for a SEPARATE tenant-level toggle. */
    public function status(Request $request)
    {
        $tenant = $request->user()->tenant;

        return response()->json([
            'tenant_device_intelligence_enabled' => $tenant->hasDeviceIntelligenceEnabled(),
        ]);
    }

    public function syncFeatureState(Request $request)
    {
        $data = $request->validate([
            'feature' => 'required|string|in:'.implode(',', array_keys(DeviceIntelligenceFeatureState::REQUIRED_ACCESS_KEYS)),
            'app_consent_status' => 'nullable|string|in:not_asked,enabled,disabled',
            'android_access' => 'nullable|array',
            'android_access.*' => 'nullable|string|in:granted,denied,restricted,not_supported,not_requested',
            'observed_at' => 'nullable|date',
        ]);

        $device = $this->deviceFromToken($request);
        $state = $this->service->syncFeatureState($device, $data['feature'], $data);

        return response()->json([
            'feature' => $state->feature,
            'app_consent_status' => $state->app_consent_status,
            'android_access' => $state->android_access,
            'activation_status' => $state->activation_status,
            'state_observed_at' => $state->state_observed_at?->toIso8601String(),
        ]);
    }

    public function syncTelemetry(Request $request)
    {
        $data = $request->validate([
            'battery_pct' => 'nullable|integer|min:0|max:100',
            'charging' => 'nullable|boolean',
            'battery_saver' => 'nullable|boolean',
            'network_type' => 'nullable|string|in:wifi,mobile,none',
            'screen_on' => 'nullable|boolean',
            'last_screen_active_at' => 'nullable|date',
            'storage_total_bytes' => 'nullable|integer|min:0',
            'storage_free_bytes' => 'nullable|integer|min:0',
            'ram_total_bytes' => 'nullable|integer|min:0',
            'ram_available_bytes' => 'nullable|integer|min:0',
            'wifi_connected' => 'nullable|boolean',
            'vpn_active' => 'nullable|boolean',
            'device_uptime_seconds' => 'nullable|integer|min:0',
        ]);

        $device = $this->deviceFromToken($request);
        $this->service->syncTelemetry($device, $data);

        return response()->json(['ok' => true]);
    }

    public function syncNotifications(Request $request)
    {
        $data = $request->validate([
            'notifications' => 'required|array|max:200',
            'notifications.*.client_notification_key' => 'required|string|max:191',
            'notifications.*.package_name' => 'required|string|max:150',
            'notifications.*.app_name' => 'nullable|string|max:150',
            'notifications.*.category' => 'nullable|string|max:60',
            'notifications.*.channel_id' => 'nullable|string|max:150',
            'notifications.*.group_key' => 'nullable|string|max:191',
            'notifications.*.conversation_title' => 'nullable|string|max:191',
            'notifications.*.sender' => 'nullable|string|max:191',
            'notifications.*.title' => 'nullable|string',
            'notifications.*.body' => 'nullable|string',
            'notifications.*.posted_at' => 'required|date',
            'notifications.*.removed' => 'nullable|boolean',
        ]);

        $device = $this->deviceFromToken($request);
        $inserted = $this->service->storeNotifications($device, $data['notifications']);

        return response()->json(['ok' => true, 'inserted' => $inserted]);
    }

    public function syncAppUsage(Request $request)
    {
        $data = $request->validate([
            'usage' => 'required|array|max:200',
            'usage.*.package_name' => 'required|string|max:150',
            'usage.*.app_name' => 'nullable|string|max:150',
            'usage.*.usage_date' => 'required|date_format:Y-m-d',
            'usage.*.duration_seconds' => 'required|integer|min:0',
            'usage.*.last_used_at' => 'nullable|date',
        ]);

        $device = $this->deviceFromToken($request);
        $count = $this->service->storeAppUsage($device, $data['usage']);

        return response()->json(['ok' => true, 'synced' => $count]);
    }

    private function deviceFromToken(Request $request): MobileDevice
    {
        $tokenId = $request->user()->currentAccessToken()->id;

        return MobileDevice::withoutGlobalScope('tenant')
            ->where('credential_token_id', $tokenId)
            ->firstOrFail();
    }
}
