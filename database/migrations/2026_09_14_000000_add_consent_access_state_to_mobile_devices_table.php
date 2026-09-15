<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side mirror of the Flutter app's two-layer Remote Support consent
 * model (see docs/remote-support-consent-model.md on the Flutter side,
 * `app/lib/remote_support/data/models/remote_support_access_state.dart`).
 * Never collapsed into the existing `permissions`/`remote_support_enabled`
 * booleans — those remain the server-side ELIGIBILITY gate
 * (RemoteSupportService::requiredPermissionsGranted /
 * MobileDevice::isEligibleForSession) and are UNCHANGED by this migration;
 * these new columns are a separate, purely informational sync of the
 * app-local consent/access state for Admin Dashboard visibility.
 *
 * `android_access` is a single JSON column (one key per capability —
 * notifications, battery_optimization_exempt, camera, microphone,
 * screen_capture — each granted|denied|restricted|not_supported|
 * not_requested), matching the existing `permissions` column's own
 * JSON-map convention rather than five separate string columns.
 *
 * `activation_status` is SERVER-COMPUTED and stored (see
 * MobileDevice::computeActivationStatus(), the exact same rule as the
 * Flutter app's `remoteSupportActivationFor`) purely so the Admin
 * Dashboard list/detail views can read it directly without recomputing the
 * rule in Blade — never trusted from the client, and never a substitute
 * for isEligibleForSession()'s own real-time computation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->string('app_consent_status', 20)->default('not_asked')->after('permissions');
            $table->json('android_access')->nullable()->after('app_consent_status');
            $table->string('activation_status', 30)->default('inactive')->after('android_access');
            $table->timestamp('consent_changed_at')->nullable()->after('activation_status');
            $table->timestamp('access_synced_at')->nullable()->after('consent_changed_at');
            $table->timestamp('remote_support_last_active_at')->nullable()->after('access_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->dropColumn([
                'app_consent_status',
                'android_access',
                'activation_status',
                'consent_changed_at',
                'access_synced_at',
                'remote_support_last_active_at',
            ]);
        });
    }
};
