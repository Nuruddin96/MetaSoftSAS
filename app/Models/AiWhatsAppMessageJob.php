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
     */
    public static function claim(int $tenantId, int $whatsAppMessageId): bool
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->where('status', 'pending')
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
}
