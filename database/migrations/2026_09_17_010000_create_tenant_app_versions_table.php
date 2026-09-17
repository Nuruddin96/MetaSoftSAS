<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE row per tenant/user, reporting the Business App version/device that
 * user's login is currently running — read by Super Admin's "App Version"
 * console (App\Http\Controllers\SuperAdmin\TenantAppVersionController) to
 * see who's on what build before deciding who needs
 * minimum_supported_build/force_update raised. Purely observational: never
 * read by AppUpdateController's own update-check response, never written
 * to by anything except Api\Mobile\TenantAppVersionController::report().
 *
 * Deliberately a NEW table rather than piggybacking on device_push_tokens
 * (FCM-token-keyed — a tenant who denies notifications may never have a
 * row there, and its schema is about push delivery, not app/device
 * telemetry) or mobile_devices (Remote Support's own device identity,
 * only populated for tenants that enabled that module — this must work
 * for every tenant regardless of Remote Support). Unique on user_id, not
 * a composite/device-scoped key: this reports "what this login is running
 * right now", upserted on every app open, exactly like device_push_tokens'
 * own upsert-by-token pattern — never an unbounded row-per-open history.
 *
 * Real migration, not database/sql/chunkN.sql — follows the same
 * established exception every mobile-app table since Remote Support/
 * Device Intelligence uses (see 2026_09_16_000500_create_app_update_
 * configs_table.php's own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_app_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20)->default('android');
            $table->string('app_version', 20);
            $table->unsignedInteger('app_build');
            $table->string('device_model', 150)->nullable();
            $table->string('os_version', 50)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique('user_id');
            $table->index('tenant_id');
            $table->index('app_build');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_app_versions');
    }
};
