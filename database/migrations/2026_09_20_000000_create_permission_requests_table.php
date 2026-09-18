<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unified permission-request lifecycle/history table — one row per
 * Super-Admin-initiated request ATTEMPT, for every requestable capability
 * (notifications, photos, location, camera, microphone, screen). Additive
 * only: it does NOT replace `mobile_devices.pending_permission_request`
 * (still what the device's heartbeat polls — see
 * DevicePermissionController's doc comment) nor
 * `device_intelligence_feature_states.pending_location_fetch_requested_at`
 * (still what the device's location-pending poll checks) — those two
 * existing single-slot "what to do right now" flags are untouched. This
 * table exists purely so Resend can create a new attempt without losing
 * the old one, and so the Super Admin panel can show a real lifecycle
 * (Sent/Delivered/Prompt shown/Allowed/Denied/Expired/...) instead of only
 * "pending vs resolved".
 *
 * `capability` covers two structurally different kinds of "request":
 * - notifications/photos/location: a genuine on-demand OS permission
 *   prompt, driven by the existing single-slot poll mechanisms above.
 * - camera/microphone/screen: NOT a separate OS permission dialog (OS
 *   grant for camera/mic happens once at setup; screen's MediaProjection
 *   consent is inherently per-process, never a server-observable
 *   "request") — for these, a "request" is the Super Admin starting a
 *   session/capability, and its outcome is observed via the SAME
 *   `capability-status` WebRTC signal the app already sends (see
 *   RemoteSupportService::pushSignal()). Rows for these three exist so the
 *   unified panel has one consistent place to show "last requested" /
 *   "last result" for every capability, not to introduce a new request
 *   mechanism for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('mobile_device_id');
            $table->string('capability', 30);

            // created -> sent -> delivered -> prompt_shown -> {allowed|denied|dismissed|failed} | expired | cancelled
            // See PermissionRequest's doc comment for the exact meaning of
            // each value and which transitions are legitimate.
            $table->string('status', 20)->default('created');

            $table->unsignedBigInteger('requested_by_super_admin_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('prompt_shown_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // The raw wire status the device reported (e.g.
            // granted/denied/denied_retryable/restricted/not_supported, or
            // a capability-state wire value for camera/mic/screen) — kept
            // verbatim alongside the normalized `status` column above so
            // Resend-eligibility (denied_retryable vs permanently denied)
            // can be read directly without re-deriving it.
            $table->string('resolved_status', 30)->nullable();

            $table->timestamp('expires_at')->nullable();

            // Self-reference — Resend creates a NEW row pointing back at
            // the attempt it supersedes, so history is a chain, never an
            // overwrite. Nullable FK, no cascade (history must survive
            // even if an intermediate row were ever removed).
            $table->unsignedBigInteger('resend_of_id')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['mobile_device_id', 'capability']);
            $table->index(['tenant_id', 'capability']);
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_requests');
    }
};
