<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized Android APK update config — a single, GLOBAL row (always
 * id=1, same "singleton settings" shape as PlatformAnnouncement, see
 * App\Models\AppUpdateConfig's docblock), never tenant-scoped: one APK
 * release applies to every tenant's copy of the mobile Business App.
 *
 * A real Laravel migration rather than a database/sql/chunkN.sql file —
 * this codebase's older core schema is SQL-file-driven (AGENTS.md
 * "Database: NOT migration-driven"), but every mobile-app-related table
 * added since Remote Support/Device Intelligence
 * (2026_08_20_220000_create_remote_support_settings_table.php onward)
 * uses a real migration instead; this follows that established, scoped
 * exception rather than the older convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_update_configs', function (Blueprint $table) {
            $table->id();
            $table->string('latest_version', 20)->default('1.0.0');
            $table->unsignedInteger('latest_build')->default(1);
            $table->string('minimum_supported_version', 20)->default('1.0.0');
            $table->unsignedInteger('minimum_supported_build')->default(1);
            // Storage path on the 'public' disk (e.g. "app-releases/xxxx.apk"),
            // never a raw filesystem path or client-supplied filename — see
            // AppUpdateConfig::apkUrl()/SuperAdmin\AppUpdateController::update()'s
            // docblocks.
            $table->string('apk_path')->nullable();
            $table->text('release_notes')->nullable();
            $table->boolean('force_update')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_update_configs');
    }
};
