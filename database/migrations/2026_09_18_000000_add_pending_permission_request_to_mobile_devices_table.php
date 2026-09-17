<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the Super-Admin-initiated "please prompt for permission X" request
 * (see App\Http\Controllers\SuperAdmin\DevicePermissionController and
 * Api\Mobile\DeviceController::resolvePermissionRequest). Single-slot by
 * design — Super Admin requests one permission at a time and waits for a
 * result before requesting the next (see DevicePermissionController's
 * docblock) — mirroring `android_access`/`app_consent_status`'s own
 * single-slot convention rather than introducing a separate request queue
 * table. Delivered to the device over the existing heartbeat poll
 * (DeviceController::heartbeat), never a new signaling channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->json('pending_permission_request')->nullable()->after('access_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->dropColumn('pending_permission_request');
        });
    }
};
