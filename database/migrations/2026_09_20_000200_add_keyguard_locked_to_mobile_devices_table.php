<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Android keyguard/lock-screen signal alongside the existing
 * `screen_on` telemetry column (see
 * 2026_09_16_000400_add_telemetry_columns_to_mobile_devices_table.php) —
 * `screen_on` alone can't distinguish ON+LOCKED from ON+UNLOCKED, which
 * `KeyguardManager.isKeyguardLocked()` reports independently of
 * `PowerManager.isInteractive()`. Nullable and additive, same as every
 * other telemetry column here: an app build that doesn't send it yet
 * simply reports unknown, never a false ON/OFF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->boolean('keyguard_locked')->nullable()->after('screen_on');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_devices', function (Blueprint $table) {
            $table->dropColumn('keyguard_locked');
        });
    }
};
