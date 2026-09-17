<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the ONE genuinely new piece of Location functionality: an
 * on-demand, Super-Admin-triggered single location snapshot — never
 * continuous tracking (see DeviceIntelligenceService::requestLocationFetch's
 * doc comment for why). Before this, `location`'s android_access/
 * consent columns already existed (see the original consent/access
 * migration) but no coordinate was ever read or stored anywhere —
 * confirmed in device.blade.php's own prior disclaimer text. Scoped
 * entirely to this existing table/module — never a new table, never
 * touching Remote Support's `mobile_devices`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_intelligence_feature_states', function (Blueprint $table) {
            $table->timestamp('pending_location_fetch_requested_at')->nullable()->after('last_active_at');
            $table->json('last_location')->nullable()->after('pending_location_fetch_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_intelligence_feature_states', function (Blueprint $table) {
            $table->dropColumn(['pending_location_fetch_requested_at', 'last_location']);
        });
    }
};
