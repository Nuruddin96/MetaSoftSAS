<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote Support module — per-tenant on/off switch, Super Admin only (see
 * docs/remote-support-architecture.md and the Phase 10/Remote Support task
 * spec). Row existence is not the gate, `enabled` is — a row is created the
 * first time a Super Admin touches the toggle for a tenant, same pattern as
 * AdBillingAccount's "one row per tenant that has the module switched on",
 * except this one is created eagerly (enabled defaults false) so the
 * Super Admin console always has something to show/toggle rather than an
 * empty state.
 *
 * Deliberately a real Laravel migration rather than a new database/sql/
 * chunkN.sql file — the rest of this codebase's schema is SQL-file-driven
 * (see AGENTS.md "Database: NOT migration-driven"), but this feature's
 * task spec explicitly asked for "proper Laravel migrations", so this is a
 * scoped, deliberate exception for the Remote Support tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_support_settings', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('remote_support_settings');
    }
};
