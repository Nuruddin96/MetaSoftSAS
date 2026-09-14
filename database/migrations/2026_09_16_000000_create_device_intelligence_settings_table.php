<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-level master toggle for the Device Intelligence module — see
 * app/Services/DeviceIntelligence/DeviceIntelligenceService.php. Mirrors
 * remote_support_settings exactly (see that migration's docblock) but is a
 * SEPARATE table/toggle: Device Intelligence and Remote Support are
 * independent modules with independent tenant-level enablement, per-device
 * consent, and Android access — one module's consent must never imply the
 * other's.
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('device_intelligence_settings');
    }
};
