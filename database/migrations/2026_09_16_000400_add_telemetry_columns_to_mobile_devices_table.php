<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device Intelligence's richer telemetry — deliberately added to the
 * EXISTING mobile_devices row (battery_pct/charging/network_type/
 * last_seen_at already live here for Remote Support's heartbeat) rather
 * than a new table, since this is the same "current device state"
 * concept, just more of it — reuses the existing device identity row per
 * the "do not create a duplicate device identity system" requirement.
 * Synced via a SEPARATE, much-less-frequent endpoint
 * (devices/intelligence/telemetry-sync) than Remote Support's 20s
 * heartbeat — see DeviceIntelligenceController::syncTelemetry().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->boolean('battery_saver')->nullable()->after('charging');
            $table->boolean('screen_on')->nullable()->after('battery_saver');
            $table->timestamp('last_screen_active_at')->nullable()->after('screen_on');
            $table->unsignedBigInteger('storage_total_bytes')->nullable()->after('last_screen_active_at');
            $table->unsignedBigInteger('storage_free_bytes')->nullable()->after('storage_total_bytes');
            $table->unsignedBigInteger('ram_total_bytes')->nullable()->after('storage_free_bytes');
            $table->unsignedBigInteger('ram_available_bytes')->nullable()->after('ram_total_bytes');
            $table->boolean('wifi_connected')->nullable()->after('ram_available_bytes');
            $table->boolean('vpn_active')->nullable()->after('wifi_connected');
            $table->unsignedBigInteger('device_uptime_seconds')->nullable()->after('vpn_active');
            $table->timestamp('telemetry_synced_at')->nullable()->after('device_uptime_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->dropColumn([
                'battery_saver', 'screen_on', 'last_screen_active_at',
                'storage_total_bytes', 'storage_free_bytes',
                'ram_total_bytes', 'ram_available_bytes',
                'wifi_connected', 'vpn_active', 'device_uptime_seconds',
                'telemetry_synced_at',
            ]);
        });
    }
};
