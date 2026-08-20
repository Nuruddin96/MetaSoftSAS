<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every Remote Support state transition — registration,
 * approval, revocation, tenant-level enable/disable, session start/
 * connect/end, permission-state changes (see docs/security-model.md §8 and
 * docs/device-lifecycle.md "Session audit trail"). A feature this
 * sensitive should never have an unauditable action path; every mutating
 * method on RemoteSupportService writes one of these in the same
 * transaction as the state change it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('mobile_device_id')->nullable();
            $table->unsignedBigInteger('remote_support_session_id')->nullable();

            $table->string('event_type', 60);
            $table->string('actor_type', 20); // super_admin|device|system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('note')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index('mobile_device_id');
            $table->index('tenant_id');
            $table->index('remote_support_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
