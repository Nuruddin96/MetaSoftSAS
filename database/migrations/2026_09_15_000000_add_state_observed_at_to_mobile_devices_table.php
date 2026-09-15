<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stale/out-of-order hardening for the consent/access sync endpoint (see
 * RemoteSupportService::syncConsentState()'s doc comment and
 * database/migrations/2026_09_14_000000_add_consent_access_state_to_mobile_devices_table.php).
 *
 * `state_observed_at` is the CLIENT-reported moment the synced snapshot
 * was captured on-device (`RemoteSupportAccessSnapshot.lastPermissionCheck`
 * on the Flutter side) — distinct from `access_synced_at` (when the SERVER
 * received the HTTP request, which always advances regardless of
 * ordering). A sync whose `observed_at` is not strictly newer than the
 * currently stored `state_observed_at` is a stale/out-of-order/duplicate
 * request: `access_synced_at` still advances (a genuine "we heard from the
 * device" fact), but `app_consent_status`/`android_access`/
 * `activation_status`/`consent_changed_at` are left untouched, so a
 * delayed retry of an older request can never regress state a newer
 * request already applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->timestamp('state_observed_at')->nullable()->after('access_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->dropColumn('state_observed_at');
        });
    }
};
