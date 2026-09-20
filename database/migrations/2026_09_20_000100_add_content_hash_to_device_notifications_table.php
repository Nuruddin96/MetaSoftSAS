<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a real message-loss bug in
 * DeviceIntelligenceService::storeNotifications(): Android reuses the
 * SAME `StatusBarNotification.getKey()` (our `client_notification_key`)
 * across UPDATES to a still-unread conversation notification — WhatsApp/
 * Messenger/imo all update one notification in place as additional
 * messages arrive in the same unread thread, rather than posting a new
 * key per message. The original unique(mobile_device_id,
 * client_notification_key) constraint was built for upload-retry safety
 * (never insert the exact same synced notification twice), but as a side
 * effect it also silently dropped every genuinely NEW message that
 * happened to share a still-open conversation's key with an
 * already-stored one — only the first message per key was ever kept.
 *
 * `content_hash` (sha256 of title|body|sender|conversation_title|
 * posted_at, computed in DeviceIntelligenceService::storeNotifications())
 * distinguishes distinct message content sharing the same key, while
 * still deduping an exact retry of the same batch (identical content →
 * identical hash). The unique constraint moves from
 * (mobile_device_id, client_notification_key) to
 * (mobile_device_id, client_notification_key, content_hash).
 *
 * Existing rows are backfilled with a hash of their own stored content so
 * the new constraint has a real value to key on immediately — this never
 * changes what's already stored, only what future inserts are compared
 * against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_notifications', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('body');
        });

        DB::table('device_notifications')->select('id', 'title', 'body', 'sender', 'conversation_title', 'posted_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('device_notifications')->where('id', $row->id)->update([
                        'content_hash' => hash('sha256', implode('|', [
                            $row->title ?? '',
                            $row->body ?? '',
                            $row->sender ?? '',
                            $row->conversation_title ?? '',
                            $row->posted_at ?? '',
                        ])),
                    ]);
                }
            });

        Schema::table('device_notifications', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable(false)->change();
            $table->dropUnique('device_notifications_device_key_unique');
            // Explicit short name — see the create-table migration's own
            // doc comment on MySQL's 64-char identifier limit.
            $table->unique(
                ['mobile_device_id', 'client_notification_key', 'content_hash'],
                'device_notifications_device_key_hash_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('device_notifications', function (Blueprint $table) {
            $table->dropUnique('device_notifications_device_key_hash_unique');
            $table->unique(['mobile_device_id', 'client_notification_key'], 'device_notifications_device_key_unique');
            $table->dropColumn('content_hash');
        });
    }
};
