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

    /**
     * True once database/sql/chunk51.sql has been imported. Message
     * coalescing (see this class's own docblock) is entirely skipped
     * when this is false — App\Jobs\ProcessAiAgentMessage and
     * MessengerWebhookController::maybeDispatchAiAgent() fall back to the
     * original one-message-one-job-one-reply behavior, never a raw SQL
     * error, same schema-readiness pattern as ::tablesReady().
     */
    public static function conversationKeyColumnReady(): bool
    {
        return Schema::hasColumn('ai_agent_message_jobs', 'conversation_key');
    }

    /** The conversation_key stamped on this row at creation time, or null if the row/column doesn't exist. */
    public static function conversationKeyFor(int $tenantId, int $messengerMessageId): ?string
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('messenger_message_id', $messengerMessageId)
            ->value('conversation_key');
    }

    /**
     * True when a message that arrived AFTER $afterMessageId, for the
     * same tenant+conversation, is still genuinely untouched ('pending')
     * — the coalescing "should I defer to a later message's own job"
     * signal. Deliberately only ever 'pending', never a stale
     * 'processing' row (a newer message whose own job already started,
     * however long ago) — waiting on that could deadlock if that job
     * crashed; the current (older) message should instead just proceed
     * with whatever is genuinely eligible right now (see
     * coalescedBatchIds()), and the stuck newer row gets reclaimed
     * independently whenever the queue redelivers its own job.
     */
    public static function hasNewerPending(int $tenantId, string $conversationKey, int $afterMessageId): bool
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('conversation_key', $conversationKey)
            ->where('messenger_message_id', '>', $afterMessageId)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * The set of message IDs that make up one coalesced logical customer
     * turn ending at $uptoMessageId (inclusive) — every 'pending' row for
     * this exact tenant+conversation up to and including it, PLUS any
     * 'processing' row stale enough that its own job attempt is
     * considered abandoned (same window ::claim() already uses) so a
     * crashed worker never permanently strands an earlier fragment out
     * of the batch. Ordered oldest-first so callers can concatenate text
     * in the order the customer actually typed it. Capped to the most
     * recent $maxBatch entries as a sanity bound against a pathological
     * burst — this is a safety ceiling, not a realistic normal-customer
     * number.
     *
     * @return array<int, int>
     */
    public static function coalescedBatchIds(int $tenantId, string $conversationKey, int $uptoMessageId, int $maxBatch = 0): array
    {
        $staleBefore = now()->subSeconds((int) config('queue.connections.database.retry_after', 90));

        $ids = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('conversation_key', $conversationKey)
            ->where('messenger_message_id', '<=', $uptoMessageId)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($staleBefore) {
                        $stale->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                    });
            })
            ->orderBy('messenger_message_id')
            ->pluck('messenger_message_id')
            ->all();

        if ($maxBatch > 0 && count($ids) > $maxBatch) {
            $ids = array_slice($ids, -$maxBatch);
        }

        return $ids;
    }

    /**
     * Batch counterpart of ::claim() — atomically claims every row in
     * $messageIds (same 'pending' OR stale-'processing' eligibility), and
     * only reports success if EVERY one of them was actually claimed by
     * this call. A partial match (some other worker or a previous
     * attempt already moved one of them) fails the whole batch rather
     * than risk processing/marking-complete a subset — the caller must
     * treat that exactly like a lost single-message claim() race and do
     * nothing further.
     */
    public static function claimBatch(int $tenantId, array $messageIds): bool
    {
        if ($messageIds === []) {
            return false;
        }

        $staleBefore = now()->subSeconds((int) config('queue.connections.database.retry_after', 90));

        $affected = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('messenger_message_id', $messageIds)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale) use ($staleBefore) {
                        $stale->where('status', 'processing')->where('updated_at', '<', $staleBefore);
                    });
            })
            ->update(['status' => 'processing', 'updated_at' => now()]);

        return $affected === count($messageIds);
    }

    /** Batch counterpart of ::markCompleted(). */
    public static function markCompletedBatch(int $tenantId, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('messenger_message_id', $messageIds)
            ->update(['status' => 'completed', 'updated_at' => now()]);
    }

    /** Batch counterpart of ::markFailed(). */
    public static function markFailedBatch(int $tenantId, array $messageIds): void
    {
        if ($messageIds === []) {
            return;
        }

        static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('messenger_message_id', $messageIds)
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
