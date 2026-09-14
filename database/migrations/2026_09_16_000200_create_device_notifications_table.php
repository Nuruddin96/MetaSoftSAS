<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captured Android notifications — see
 * app/Services/DeviceIntelligence/DeviceIntelligenceService.php's
 * storeNotifications() and NotificationCaptureListenerService.kt (the ONE
 * legitimate source: Android's own NotificationListenerService, never a
 * private app database, root, or Accessibility-as-covert-reading — see
 * docs/device-intelligence-architecture.md).
 *
 * `client_notification_key` is Android's own `StatusBarNotification.getKey()`
 * — stable per notification post, which is what makes upload idempotent:
 * the unique(mobile_device_id, client_notification_key) constraint means a
 * retried/duplicate batch upload can never insert the same notification
 * twice (insertOrIgnore-style dedup, no separate dedup table needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('mobile_device_id');
            $table->string('client_notification_key', 191);
            $table->string('package_name', 150);
            $table->string('app_name', 150)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('channel_id', 150)->nullable();
            $table->string('group_key', 191)->nullable();
            $table->string('conversation_title', 191)->nullable();
            $table->string('sender', 191)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('body')->nullable();
            $table->timestamp('posted_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['mobile_device_id', 'client_notification_key']);
            $table->index(['mobile_device_id', 'posted_at']);
            $table->index(['tenant_id', 'package_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_notifications');
    }
};
