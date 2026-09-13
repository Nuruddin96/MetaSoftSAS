<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote Support FCM wake PROOF-OF-CONCEPT only (see
 * RemoteSupportFcmService.kt on the Android side and
 * RemoteSupportTestWake on this side). Stores the device's current FCM
 * registration token so a manual test-send command has something to target
 * — nothing in the existing heartbeat/session/eligibility logic reads or
 * depends on this column; it exists purely to let a developer push a test
 * wake message to a specific device during feasibility testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->string('fcm_token', 255)->nullable()->after('credential_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->dropColumn('fcm_token');
        });
    }
};
