<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency/tracking record for the queued WhatsApp AI reply job
 * (App\Jobs\ProcessWhatsAppAiAgentMessage) — the WhatsApp counterpart of
 * AiAgentMessageJob (Messenger). See database/sql/chunk35.sql for the
 * full design rationale — the short version: the webhook writes a
 * 'pending' row synchronously right before dispatching the job, and the
 * job can only proceed by winning a conditional
 * UPDATE ... WHERE status = 'pending' (see ::claim()) — this is the
 * duplicate-reply guard, not the queue itself.
 */
class AiWhatsAppMessageJob extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_whatsapp_message_jobs';

    protected $guarded = [];

    /**
     * True once database/sql/chunk35.sql has been imported. Same
     * schema-readiness guard shape as AiAgentMessageJob::tablesReady() —
     * a deploy that lands before chunk35.sql is imported degrades to "AI
     * agent not available for WhatsApp yet" instead of a raw SQL error on
     * every WhatsApp webhook delivery.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('ai_whatsapp_message_jobs');
    }

    /**
     * Atomically claims this row for processing — the sole duplicate-send
     * guard for App\Jobs\ProcessWhatsAppAiAgentMessage. Returns true only
     * for the single execution that wins the pending -> processing
     * transition; a retried/duplicate job execution sees 0 affected rows
     * and must stop without generating or sending anything.
     *
     * Phase 15 — mirrors AiAgentMessageJob::claim()'s stale-reclaim
     * addition one-for-one; see that method's docblock for the full
     * "worker died mid-flight, would otherwise drop this message
     * forever" reasoning.
     */
    public static function claim(int $tenantId, int $whatsAppMessageId): bool
    {
        $staleBefore = now()->subSeconds((int) config('queue.connections.database.retry_after', 90));

        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($staleBefore) {
                        $stale->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                    });
            })
            ->update(['status' => 'processing', 'updated_at' => now()]) === 1;
    }

    public static function markCompleted(int $tenantId, int $whatsAppMessageId): void
    {
        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    public static function markFailed(int $tenantId, int $whatsAppMessageId): void
    {
        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }

    /**
     * Mirrors AiAgentMessageJob's coalescing additions one-for-one (see
     * that class's docblocks for the full design) — database/sql/
     * chunk51.sql adds the same conversation_key column to this table.
     */
    public static function conversationKeyColumnReady(): bool
    {
        return Schema::hasColumn('ai_whatsapp_message_jobs', 'conversation_key');
    }

    public static function conversationKeyFor(int $tenantId, int $whatsAppMessageId): ?string
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->value('conversation_key');
    }

    public static function hasNewerPending(int $tenantId, string $conversationKey, int $afterMessageId): bool
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('conversation_key', $conversationKey)
            ->where('whatsapp_message_id', '>', $afterMessageId)
            ->where('status', 'pending')
            ->exists();
    }

    /** @return array<int, int> */
    public static function coalescedBatchIds(int $tenantId, string $conversationKey, int $uptoMessageId, int $maxBatch = 0): array
    {
        $staleBefore = now()->subSeconds((int) config('queue.connections.database.retry_after', 90));

        $ids = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('conversation_key', $conversationKey)
            ->where('whatsapp_message_id', '<=', $uptoMessageId)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($staleBefore) {
                        $stale->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                    });
            })
            ->orderBy('whatsapp_message_id')
            ->pluck('whatsapp_message_id')
            ->all();

        if ($maxBatch > 0 && count($ids) > $maxBatch) {
            $ids = array_slice($ids, -$maxBatch);
        }

        return $ids;
    }

    public static function claimBatch(int $tenantId, array $messageIds): bool
    {
        if ($messageIds === []) {
            return false;
        }

        $staleBefore = now()->subSeconds((int) config('queue.connections.database.retry_after', 90));

        $affected = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('whatsapp_message_id', $messageIds)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($staleBefore) {
                        $stale->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                    });
            })
            ->update(['status' => 'processing', 'updated_at' => now()]);

        return $affected === count($messageIds);
    }

    public static function markCompletedBatch(int $tenantId, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('whatsapp_message_id', $messageIds)
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    public static function markFailedBatch(int $tenantId, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('whatsapp_message_id', $messageIds)
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
