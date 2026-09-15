<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Remote Support viewing session (device-lifecycle.md's
 * transient `IN SESSION` state). `session_token` is a third, distinct
 * credential from the device's login/heartbeat credential — scoped only to
 * this one session's signaling exchange, so a device credential leak alone
 * can never be replayed into an unrelated session and a session token
 * alone can never be used to heartbeat or start a *new* session.
 *
 * `expires_at` is a hard cap set at creation (see
 * RemoteSupportService::MAX_SESSION_MINUTES) — a session that is never
 * explicitly stopped still cannot signal past this timestamp, so a stuck
 * "active" row can't linger indefinitely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_support_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('mobile_device_id');
            $table->unsignedBigInteger('started_by_super_admin_id');

            $table->string('status', 20)->default('pending'); // pending|active|ended
            $table->string('session_token', 64)->unique();

            $table->boolean('include_microphone')->default(false);
            $table->boolean('include_camera')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 40)->nullable(); // stopped_by_admin|device_declined|device_offline|timeout|ice_failed|expired

            $table->timestamp('expires_at');

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('mobile_device_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_support_sessions');
    }
};
