<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Device Intelligence analogue of mobile_devices' app_consent_status/
 * android_access/activation_status columns (see
 * 2026_09_14_000000_add_consent_access_state_to_mobile_devices_table.php)
 * — but MULTIPLEXED by feature, since Device Intelligence has several
 * independent features (notification_monitoring, app_usage, device_health,
 * location) each with its OWN consent/access/activation, whereas Remote
 * Support only ever had one. One row per (mobile_device_id, feature).
 *
 * Deliberately a separate table from mobile_devices' own columns — never
 * merges Device Intelligence's consent state into Remote Support's, and
 * never shares a "one flag rules everything" design (see the Flutter
 * app's DeviceIntelligenceFeature-keyed local storage, kept in a
 * completely separate module from remote_support's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_intelligence_feature_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('mobile_device_id');
            // notification_monitoring | app_usage | device_health | location
            $table->string('feature', 40);
            $table->string('app_consent_status', 20)->default('not_asked');
            $table->json('android_access')->nullable();
            $table->string('activation_status', 30)->default('inactive');
            $table->timestamp('consent_changed_at')->nullable();
            $table->timestamp('access_synced_at')->nullable();
            $table->timestamp('state_observed_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['mobile_device_id', 'feature']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_intelligence_feature_states');
    }
};
