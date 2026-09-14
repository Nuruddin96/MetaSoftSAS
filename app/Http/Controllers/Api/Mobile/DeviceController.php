<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\RemoteSupportSession;
use App\Services\RemoteSupport\RemoteSupportService;
use Illuminate\Http\Request;

/**
 * Remote Support device-agent endpoints. `register`/`status` run under the
 * user's own login token (`bind.tenant.token`, same as every other mobile
 * endpoint) — the device doesn't have its own credential yet at that
 * point. `heartbeat` runs under the separate device credential instead
 * (`ability:device:heartbeat`, see MobileDevice's docblock) so it keeps
 * working even after the human logs out of the app on that phone.
 *
 * Every action here resolves the target MobileDevice from the
 * authenticated identity itself (the tenant-bound user for
 * register/status, the device-credential token for heartbeat) — never
 * from a client-supplied device id — so one tenant's device can never
 * heartbeat or read another tenant's device row.
 */
class DeviceController extends Controller
{
    public function __construct(protected RemoteSupportService $service) {}

    /**
     * The Flutter app calls this only AFTER the tenant has tapped "Allow
     * Access" and the required on-device permissions were actually
     * granted (see SetupController.requestAccess() on the Dart side) —
     * never before, and never as a prerequisite to showing that screen.
     * Auto-approves immediately, no verification code — see
     * RemoteSupportService::registerDevice()'s doc comment for why that's
     * still a safe trust model: tenant-level enable, checked below, is
     * the real gate.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'device_uuid' => 'required|string|max:64',
            'platform' => 'nullable|string|max:20',
            'device_model' => 'nullable|string|max:150',
            'os_version' => 'nullable|string|max:50',
            'app_version' => 'nullable|string|max:30',
        ]);

        $tenant = $request->user()->tenant;
        abort_unless($tenant->hasRemoteSupportEnabled(), 404);

        $result = $this->service->registerDevice($request->user(), $data);
        $device = $result['device'];

        return response()->json([
            'device_uuid' => $device->device_uuid,
            'status' => $device->status,
            'device_token' => $result['device_token'],
        ], 201);
    }

    /** Lets the app decide, at any point, whether to show the (nav-hidden) Remote Support setup screen at all. */
    public function status(Request $request)
    {
        $tenant = $request->user()->tenant;

        $device = MobileDevice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->where('device_uuid', $request->query('device_uuid'))
            ->first();

        return response()->json([
            'tenant_remote_support_enabled' => $tenant->hasRemoteSupportEnabled(),
            'device' => $device ? [
                'device_uuid' => $device->device_uuid,
                'status' => $device->liveStatus(),
                'remote_support_enabled' => $device->remote_support_enabled,
                'permissions' => $device->permissions,
            ] : null,
        ]);
    }

    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'battery_pct' => 'nullable|integer|min:0|max:100',
            'charging' => 'nullable|boolean',
            'network_type' => 'nullable|string|in:wifi,mobile,none',
            'foreground_service_running' => 'nullable|boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => 'boolean',
        ]);

        $device = $this->deviceFromToken($request);

        $device = $this->service->recordHeartbeat($device, $data);

        // No push infrastructure (FCM) is wired into this codebase today
        // (no Firebase credentials configured — see docs/webrtc-flow.md's
        // own "not decided yet" note on signaling transport) — so the
        // device's own heartbeat, not a push wake, is how it discovers a
        // Super Admin started a session. Heartbeat interval is the wake
        // latency ceiling; see RemoteSupportForegroundService's Kotlin-side
        // doc comment for the interval this trades off against battery use.
        $activeSession = $device->sessions()
            ->where('status', '!=', RemoteSupportSession::STATUS_ENDED)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        return response()->json([
            'status' => $device->status,
            'remote_support_enabled' => $device->remote_support_enabled,
            'active_session' => $activeSession ? [
                'session_token' => $activeSession->session_token,
                'include_microphone' => $activeSession->include_microphone,
                'include_camera' => $activeSession->include_camera,
                'ice_servers' => $this->service->iceServers(),
            ] : null,
        ]);
    }

    /**
     * Remote Support FCM wake PROOF-OF-CONCEPT only — see
     * RemoteSupportFcmService.kt (Android) and RemoteSupportTestWake
     * (this side's manual test-send command). Stores the device's current
     * FCM registration token so that command has something to target.
     * Deliberately not called from anywhere in the existing Dart code yet
     * (no automatic registration is wired up) — during this feasibility
     * test the token is copied manually from logcat and posted here (or
     * inserted directly) rather than the app calling this on its own.
     * Reuses the same device-credential token/ability as heartbeat — no
     * new Sanctum ability introduced for this proof-of-concept.
     */
    public function updateFcmToken(Request $request)
    {
        $data = $request->validate([
            'fcm_token' => 'required|string|max:255',
        ]);

        $device = $this->deviceFromToken($request);
        $device->fcm_token = $data['fcm_token'];
        $device->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Syncs the Flutter app's LOCAL app-consent/Android-access state to
     * this device row for Admin Dashboard visibility only — see
     * RemoteSupportService::syncConsentState()'s doc comment. Called from
     * SetupController's existing evaluation path (consent change, Android
     * access change, app start/resume, or any other re-evaluation) with
     * built-in change detection on the Dart side, so this is expected to
     * be hit far less often than heartbeat, not on every 20s tick. Reuses
     * the same device-credential ability as heartbeat/fcm-token — no new
     * Sanctum ability introduced.
     */
    public function syncConsent(Request $request)
    {
        $data = $request->validate([
            'app_consent_status' => 'nullable|string|in:not_asked,enabled,disabled',
            'android_access' => 'nullable|array',
            'android_access.notifications' => 'nullable|string|in:granted,denied,restricted,not_supported,not_requested',
            'android_access.battery_optimization_exempt' => 'nullable|string|in:granted,denied,restricted,not_supported,not_requested',
            'android_access.camera' => 'nullable|string|in:granted,denied,restricted,not_supported,not_requested',
            'android_access.microphone' => 'nullable|string|in:granted,denied,restricted,not_supported,not_requested',
            'android_access.screen_capture' => 'nullable|string|in:granted,denied,restricted,not_supported,not_requested',
            // Stale/out-of-order hardening — see
            // RemoteSupportService::syncConsentState()'s doc comment.
            // Optional: a request without it is always applied.
            'observed_at' => 'nullable|date',
        ]);

        $device = $this->deviceFromToken($request);
        $device = $this->service->syncConsentState($device, $data);

        return response()->json([
            'app_consent_status' => $device->app_consent_status,
            'android_access' => $device->android_access,
            'activation_status' => $device->activation_status,
            'state_observed_at' => $device->state_observed_at?->toIso8601String(),
            'consent_changed_at' => $device->consent_changed_at?->toIso8601String(),
            'remote_support_last_active_at' => $device->remote_support_last_active_at?->toIso8601String(),
        ]);
    }

    public function deviceFromToken(Request $request): MobileDevice
    {
        $tokenId = $request->user()->currentAccessToken()->id;

        return MobileDevice::withoutGlobalScope('tenant')
            ->where('credential_token_id', $tokenId)
            ->firstOrFail();
    }
}
