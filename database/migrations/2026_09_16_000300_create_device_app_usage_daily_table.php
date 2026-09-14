<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily per-app usage totals from Android's UsageStatsManager
 * (INTERVAL_DAILY) — see UsageStatsHelper.kt. One row per (device,
 * package, calendar date), upserted idempotently as the day progresses
 * (usage duration only ever grows for "today", so re-syncing the same day
 * just updates duration_seconds in place — no duplicate rows, no separate
 * dedup table). Today/7-day/30-day/top-apps are all simple aggregate
 * queries over this table rather than a raw event stream, which keeps
 * upload volume small (one row per app per day, not one row per app
 * launch) — see the "don't upload unchanged telemetry continuously"
 * requirement in docs/device-intelligence-architecture.md.
 */
return new class extends Migration
{
    public function up(): void
    {
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
            $table->index(['mobile_device_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_app_usage_daily');
    }
};
