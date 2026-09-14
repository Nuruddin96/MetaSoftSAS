<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal, test-only schema for Device Intelligence — same rationale as
 * InteractsWithRemoteSupportSchema (this project's real schema lives in
 * database/sql/, not database/migrations/, for most tables; Device
 * Intelligence follows Remote Support's own precedent of real migrations
 * for this feature family instead). Composes
 * InteractsWithRemoteSupportSchema for tenants/users/mobile_devices/
 * super_admins, then adds the telemetry columns and the four new tables.
 */
trait InteractsWithDeviceIntelligenceSchema
{
    use InteractsWithRemoteSupportSchema;

    protected function setUpDeviceIntelligenceSchema(): void
    {
        $this->setUpRemoteSupportSchema();

        if (! Schema::hasColumn('mobile_devices', 'battery_saver')) {
            Schema::table('mobile_devices', function (Blueprint $table) {
                $table->boolean('battery_saver')->nullable();
                $table->boolean('screen_on')->nullable();
                $table->timestamp('last_screen_active_at')->nullable();
                $table->unsignedBigInteger('storage_total_bytes')->nullable();
                $table->unsignedBigInteger('storage_free_bytes')->nullable();
                $table->unsignedBigInteger('ram_total_bytes')->nullable();
                $table->unsignedBigInteger('ram_available_bytes')->nullable();
                $table->boolean('wifi_connected')->nullable();
                $table->boolean('vpn_active')->nullable();
                $table->unsignedBigInteger('device_uptime_seconds')->nullable();
                $table->timestamp('telemetry_synced_at')->nullable();
            });
        }

        if (! Schema::hasTable('device_intelligence_settings')) {
            Schema::create('device_intelligence_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->unsignedBigInteger('enabled_by_super_admin_id')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->unsignedBigInteger('disabled_by_super_admin_id')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('device_intelligence_feature_states')) {
            Schema::create('device_intelligence_feature_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('mobile_device_id');
                $table->string('feature', 40);
                $table->string('app_consent_status', 20)->default('not_asked');
                $table->json('android_access')->nullable();
                $table->string('activation_status', 30)->default('inactive');
                $table->timestamp('consent_changed_at')->nullable();
                $table->timestamp('access_synced_at')->nullable();
                $table->timestamp('state_observed_at')->nullable();
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();
                // Matches the migration's explicit short constraint name
                // — see that file's doc comment on the MySQL 64-char
                // identifier-length limit this avoids.
                $table->unique(['mobile_device_id', 'feature'], 'di_feature_states_device_feature_unique');
            });
        }

        if (! Schema::hasTable('device_notifications')) {
            Schema::create('device_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('mobile_device_id');
                $table->string('client_notification_key', 191);
                $table->string('package_name', 150);
                $table->string('app_name', 150)->nullable();
                $table->string('category', 60)->nullable();
                $table->string('channel_id', 150)->nullable();
                $table->string('group_key', 191)->nullable();
                $table->string('conversation_title', 191)->nullable();
                $table->string('sender', 191)->nullable();
                $table->string('title', 255)->nullable();
                $table->text('body')->nullable();
                $table->timestamp('posted_at');
                $table->timestamp('removed_at')->nullable();
                $table->timestamps();
                $table->unique(['mobile_device_id', 'client_notification_key'], 'device_notifications_device_key_unique');
            });
        }

        if (! Schema::hasTable('device_app_usage_daily')) {
            Schema::create('device_app_usage_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('mobile_device_id');
                $table->string('package_name', 150);
                $table->string('app_name', 150)->nullable();
                $table->date('usage_date');
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->unique(['mobile_device_id', 'package_name', 'usage_date'], 'device_app_usage_unique');
            });
        }
    }
}
