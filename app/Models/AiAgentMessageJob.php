<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency/tracking record for the queued AI reply job
 * (App\Jobs\ProcessAiAgentMessage). See database/sql/chunk30.sql for the
 * full design rationale — the short version: the webhook writes a
 * 'pending' row synchronously right before dispatching the job, and the
 * job can only proceed by winning a conditional
 * UPDATE ... WHERE status = 'pending' (see ::claim()) — this is the
 * duplicate-reply guard, not the queue itself.
 */
class AiAgentMessageJob extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_agent_message_jobs';

    protected $guarded = [];

    /**
     * True once database/sql/chunk30.sql has been imported. Same
     * schema-readiness guard shape as FacebookPage::tablesReady() /
     * MessengerMessage::attachmentColumnsReady() — call sites that would
     * otherwise write to this table unconditionally must check this
     * first, so a deploy that lands before chunk30.sql is imported
     * degrades to "AI agent not available yet" instead of a raw SQL
     * error on every Messenger webhook delivery.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('ai_agent_message_jobs');
    }

    /**
     * Atomically claims this row for processing — the sole duplicate-send
     * guard for App\Jobs\ProcessAiAgentMessage. Returns true only for the
     * single execution that wins the pending -> processing transition;
     * a retried/duplicate job execution sees 0 affected rows and must
     * stop without generating or sending anything.
     *
     * Phase 15 — ALSO reclaims a 'processing' row that has sat untouched
     * longer than the queue's own retry_after window. Without this, a
     * worker that dies mid-flight (OOM, server restart, hitting this
     * job's own $timeout on an environment without pcntl to enforce it —
     * see ProcessAiAgentMessage::$timeout's docblock) leaves the row
     * stuck in 'processing' forever: every later attempt — including
     * Laravel's own automatic retry once retry_after elapses and the
     * queue driver considers the underlying job abandoned — would see
     * status='processing', find 0 affected rows here, and silently do
     * nothing, permanently dropping that customer's message with no
     * error anywhere. Reusing the queue connection's own retry_after
     * value (rather than a second, independently-tunable number) keeps
     * this reclaim window aligned with when Laravel's own redelivery
     * actually happens, rather than racing ahead of or trailing behind
     * it. The UPDATE stays a single atomic SQL statement either way — a
     * second worker racing the same reclaim still only ever affects 0 or
     * 1 row, never both.
     */
    public static function claim(int $tenantId, int $messengerMessageId): bool
    {
        $staleBefore = now()->subSeconds((int) config('queue.connections.database.retry_after', 90));

        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('messenger_message_id', $messengerMessageId)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($staleBefore) {
                        $stale->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                    });
            })
            ->update(['status' => 'processing', 'updated_at' => now()]) === 1;
    }

    public static function markCompleted(int $tenantId, int $messengerMessageId): void
    {
        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('messenger_message_id', $messengerMessageId)
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    public static function markFailed(int $tenantId, int $messengerMessageId): void
    {
        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('messenger_message_id', $messengerMessageId)
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
