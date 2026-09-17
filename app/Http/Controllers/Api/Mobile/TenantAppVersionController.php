<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TenantAppVersion;
use App\Models\TenantAppVersionHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $now = now();

        $current = TenantAppVersion::where('user_id', $user->id)->first();

        TenantAppVersion::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => $user->tenant_id,
                'platform' => $data['platform'] ?? 'android',
                'app_version' => $data['app_version'],
                'app_build' => $data['app_build'],
                'device_model' => $data['device_model'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'last_seen_at' => $now,
            ]
        );

        if (TenantAppVersionHistory::tablesReady()) {
            $this->recordHistory($current, $user, $data, $now);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A genuine version CHANGE (or the very first report this user has
     * ever sent) starts a new history row; a report matching what's
     * already current just extends the latest row's last_seen_at — see
     * the tenant_app_version_history migration's own docblock for why.
     * $current is the PRE-update TenantAppVersion snapshot (null on a
     * first-ever report), so this always compares against what the
     * tenant was on a moment ago, never the row this same request just
     * wrote.
     */
    private function recordHistory(?TenantAppVersion $current, User $user, array $data, Carbon $now): void
    {
        $changed = ! $current
            || $current->app_version !== $data['app_version']
            || $current->app_build !== $data['app_build'];

        if (! $changed) {
            TenantAppVersionHistory::where('user_id', $user->id)
                ->latest('id')
                ->first()
                ?->update([
                    'last_seen_at' => $now,
                    'device_model' => $data['device_model'] ?? null,
                    'os_version' => $data['os_version'] ?? null,
                ]);

            return;
        }

        TenantAppVersionHistory::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'source' => 'app_version_report',
            'platform' => $data['platform'] ?? 'android',
            'app_version' => $data['app_version'],
            'app_build' => $data['app_build'],
            'device_model' => $data['device_model'] ?? null,
            'os_version' => $data['os_version'] ?? null,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
