<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A registered Android device, always tied to the tenant user account that
 * enrolled it (`user_id`). Trust is three-tier and ALL required
 * simultaneously (see docs/security-model.md §3 and
 * RemoteSupportService::isSessionEligible()):
 *  1. `status` reaching `on_ready` (device identity + Super Admin approval
 *     + the device's own Android-side preconditions all satisfied),
 *  2. `remote_support_settings.enabled` for the tenant,
 *  3. `remote_support_enabled` on this device row,
 *  4. and even then, MediaProjection consent is re-asked every session on
 *     the device itself — nothing server-side can imply that away.
 *
 * `credential_token_id` points at a *separate* Sanctum personal access
 * token from the user's own login token (see AGENTS.md's Sanctum usage
 * and docs/security-model.md §4) — issued with device-scoped abilities
 * (`device:heartbeat`, `device:signal`) at registration time, so revoking
 * a user's login session never silently revokes device trust and vice
 * versa. Reusing Sanctum's existing token table/hashing instead of a new
 * bespoke credentials table keeps this additive rather than duplicating
 * infrastructure that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('device_uuid', 64)->unique();
            $table->string('platform', 20)->default('android');
            $table->string('device_model', 150)->nullable();
            $table->string('os_version', 50)->nullable();
            $table->string('app_version', 30)->nullable();

            // pending_verification -> off -> on_not_ready -> on_ready -> offline -> revoked
            // See docs/device-lifecycle.md for the full state machine.
            $table->string('status', 30)->default('pending_verification');

            $table->string('verification_code', 12)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('approved_by_super_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('revoked_by_super_admin_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();

            // Device-level Remote Support toggle — independent of, and
            // required alongside, the tenant-level one in
            // remote_support_settings (both must be true).
            $table->boolean('remote_support_enabled')->default(false);

            $table->unsignedBigInteger('credential_token_id')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->boolean('charging')->nullable();
            $table->string('network_type', 20)->nullable(); // wifi|mobile|none
            $table->json('permissions')->nullable(); // {"notifications": true, "battery_optimization_exempt": true, ...}
            $table->boolean('foreground_service_running')->default(false);

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
