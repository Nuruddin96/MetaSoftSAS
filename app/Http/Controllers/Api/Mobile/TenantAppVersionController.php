<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TenantAppVersion;
use Illuminate\Http\Request;

/**
 * Tenant-wise App Version Tracking — lightweight telemetry only, read by
 * Super Admin's "App Version" console (SuperAdmin\TenantAppVersionController)
 * to see who's on which build before deciding who needs a raised
 * minimum_supported_build/force_update. Never itself decides or triggers
 * anything update-related — see AppUpdateController for that, entirely
 * unaffected by this endpoint.
 */
class TenantAppVersionController extends Controller
{
    /**
     * Upsert-by-user_id, same "one row per login, never one row per app
     * open" shape as NotificationController::registerDeviceToken()'s own
     * upsert-by-token. Called from the Flutter app on every normal
     * authenticated startup — see PushNotificationService's own
     * syncTokenWithBackend() call site in app.dart for the identical
     * "justAuthenticated" trigger this reuses.
     */
    public function report(Request $request)
    {
        $data = $request->validate([
            'app_version' => 'required|string|max:20',
            'app_build' => 'required|integer|min:1',
            'platform' => 'nullable|string|max:20',
            'device_model' => 'nullable|string|max:150',
            'os_version' => 'nullable|string|max:50',
        ]);

        if (! TenantAppVersion::tablesReady()) {
            return response()->json(['ok' => false], 503);
        }

        $user = $request->user();

        TenantAppVersion::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'platform' => $data['platform'] ?? 'android',
                'app_version' => $data['app_version'],
                'app_build' => $data['app_build'],
                'device_model' => $data['device_model'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }
}
