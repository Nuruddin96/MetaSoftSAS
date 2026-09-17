<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per CONTIGUOUS run of a tenant/user reporting the same
 * (app_version, app_build) — a genuine version CHANGE (or the very first
 * report ever) starts a new row; repeated reports of the same version
 * just extend the existing row's last_seen_at, so this never grows
 * unboundedly the way one-row-per-report would. TenantAppVersion (the
 * existing table) stays exactly as it was — the fast "current version"
 * lookup, upserted in place, never historical. This table is the
 * separate, append-mostly history those same reports also feed, written
 * by Api\Mobile\TenantAppVersionController::report() alongside its
 * existing TenantAppVersion::updateOrCreate() call.
 *
 * `source` distinguishes rows actually written by THIS feature
 * ('app_version_report') from rows backfilled from Remote Support's own,
 * differently-versioned mobile_devices.app_version snapshots
 * ('remote_support_registration', see the one-off backfill run
 * alongside this migration's deploy) — never silently blended as if
 * both were the same kind of evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_app_version_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 30)->default('app_version_report');
            $table->string('platform', 20)->default('android');
            $table->string('app_version', 20);
            $table->unsignedInteger('app_build');
            $table->string('device_model', 150)->nullable();
            $table->string('os_version', 50)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at']);
            $table->index('tenant_id');
            $table->index('app_build');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_app_version_history');
    }
};
