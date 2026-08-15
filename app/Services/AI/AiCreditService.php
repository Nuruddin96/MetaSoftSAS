<?php

namespace App\Services\AI;

use App\Models\AiCreditAccount;
use App\Models\AiUsageLedger;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for a tenant's AI credit balance. Every balance
 * mutation (Super Admin allocation, manual adjustment, per-call usage
 * deduction) goes through here, and every reader (Settings page, Super
 * Admin console) reads through here — so the balance is never computed or
 * written ad-hoc in a controller/job.
 *
 * Modeled on App\Services\Advertising\AdvertisingBalanceService but
 * deliberately NOT a copy — see database/sql/chunk32.sql's docblock for
 * the specific differences (no is_active switch; adds token/model/context
 * columns for usage rows).
 *
 * tenant_id is always explicit and every query uses withoutGlobalScopes(),
 * because this service is called both from a tenant-bound request
 * (Settings page) and from super-admin / queued-job contexts where
 * app('currentTenant') is never bound (see BelongsToTenant) — relying on
 * the ambient global scope here would silently return unscoped data in
 * those cases instead of failing loudly. This is also why every public
 * method takes tenant_id as an explicit int parameter, never reads it
 * from anywhere ambient — callers (see ProcessAiAgentMessage) must always
 * pass a server-derived tenant_id, never one supplied by the AI or by
 * customer input.
 */
class AiCreditService
{
    public function getAccount(int $tenantId): ?AiCreditAccount
    {
        if (! AiCreditAccount::tablesReady()) {
            return null;
        }

        return AiCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Cached tenant-facing balance — a single indexed row read, never a
     * ledger SUM. Returns null when the tenant has never been allocated
     * credit at all (caller should treat that the same as "0 balance",
     * not as "unlimited").
     */
    public function balance(int $tenantId): ?string
    {
        return $this->getAccount($tenantId)?->balance;
    }

    /**
     * The entire "can this tenant's AI Agent make an OpenAI call right
     * now" check. Strictly greater than $minBalance (default 0) — a
     * balance of exactly 0 is "exhausted", not "just barely enough". No
     * account row at all (never allocated) is the same as 0.
     */
    public function hasCredit(int $tenantId, float $minBalance = 0.0): bool
    {
        if (! AiCreditAccount::tablesReady()) {
            return false;
        }

        $balance = $this->balance($tenantId);

        return $balance !== null && (float) $balance > $minBalance;
    }

    /** Super-admin: allocates credit to a tenant. Creates the account row on first allocation — no separate "activate" step. */
    public function allocate(int $tenantId, float $amount, ?string $note, int $superAdminId): AiUsageLedger
    {
        return $this->applyEntry($tenantId, 'allocation', $amount, $note, adminId: $superAdminId);
    }

    /** Super-admin: manual balance correction, either direction. */
    public function adjust(int $tenantId, float $amount, string $direction, string $note, int $superAdminId): AiUsageLedger
    {
        $type = $direction === 'credit' ? 'adjustment_credit' : 'adjustment_debit';

        return $this->applyEntry($tenantId, $type, $amount, $note, adminId: $superAdminId);
    }

    /**
     * Records one completed OpenAI call's actual usage and deducts the
     * corresponding credit. Called only AFTER a successful reply is
     * generated (see ProcessAiAgentMessage) — the OpenAI cost is incurred
     * the moment the API call succeeds, regardless of what happens
     * afterward (e.g. a subsequent Messenger-send failure), so deduction
     * must not wait on that later step.
     *
     * credit_amount deducted uses config('ai.credit_per_1k_tokens') — a
     * tenant-facing rate the operator controls directly. estimated_cost_usd
     * is a SEPARATE, admin-only estimate from config('ai.pricing'), never
     * used to gate or deduct credit — see chunk32.sql's docblock.
     */
    public function recordUsage(
        int $tenantId,
        int $inputTokens,
        int $outputTokens,
        string $model,
        string $contextType,
        ?int $contextId = null,
    ): ?AiUsageLedger {
        if (! AiCreditAccount::tablesReady()) {
            return null;
        }

        $totalTokens = $inputTokens + $outputTokens;
        $creditAmount = round(($totalTokens / 1000) * (float) config('ai.credit_per_1k_tokens', 1.0), 4);
        $estimatedCostUsd = $this->estimateCostUsd($inputTokens, $outputTokens, $model);

        return DB::transaction(function () use ($tenantId, $creditAmount, $inputTokens, $outputTokens, $model, $estimatedCostUsd, $contextType, $contextId) {
            $account = AiCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                // No wallet at all for this tenant — nothing to deduct
                // from. Should never actually be reached in practice
                // (hasCredit() is checked before any OpenAI call is made),
                // but this method must never create a wallet implicitly —
                // only allocate()/adjust() (explicit Super Admin actions)
                // may do that.
                return null;
            }

            $account->decrement('balance', $creditAmount);

            return AiUsageLedger::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'type' => 'usage',
                'credit_amount' => $creditAmount,
                'balance_after' => $account->fresh()->balance,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'model' => $model,
                'estimated_cost_usd' => $estimatedCostUsd,
                'context_type' => $contextType,
                'context_id' => $contextId,
            ]);
        });
    }

    /**
     * Paginated ledger for one tenant, optionally filtered by type(s).
     * $tenantVisibleOnly strips estimated_cost_usd, input/output tokens,
     * model, context_type/context_id, and created_by before the caller
     * ever sees them — pass true from every tenant-facing controller.
     * This is a SQL-level select() restriction, not merely hiding fields
     * in Blade.
     */
    public function ledger(int $tenantId, array $types = [], int $perPage = 30, bool $tenantVisibleOnly = false)
    {
        $query = AiUsageLedger::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($types, fn ($q) => $q->whereIn('type', $types))
            ->orderByDesc('id');

        if ($tenantVisibleOnly) {
            $query->select(AiUsageLedger::TENANT_VISIBLE_COLUMNS);
        } else {
            $query->with('admin');
        }

        return $query->paginate($perPage);
    }

    /** Admin-only informational estimate — never read by any tenant-facing code, never used to gate/deduct credit. */
    protected function estimateCostUsd(int $inputTokens, int $outputTokens, string $model): float
    {
        $pricing = config('ai.pricing.'.$model) ?? config('ai.pricing.default', ['input' => 0, 'output' => 0]);

        return round(
            ($inputTokens / 1000 * (float) $pricing['input']) + ($outputTokens / 1000 * (float) $pricing['output']),
            6
        );
    }

    /**
     * Core, single write path for Super Admin mutations — every public
     * allocate()/adjust() call funnels through here. recordUsage() above
     * has its own, deliberately non-creating variant of this same
     * lock-and-decrement shape. Locks the account row for the duration of
     * the transaction (same lockForUpdate()-for-money-math pattern
     * AdvertisingBalanceService::applyEntry() already uses) and mutates
     * balance via Eloquent's atomic increment()/decrement() rather than
     * read-modify-write.
     */
    protected function applyEntry(int $tenantId, string $type, float $amount, ?string $note, int $adminId): AiUsageLedger
    {
        return DB::transaction(function () use ($tenantId, $type, $amount, $note, $adminId) {
            $account = AiCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                // First-ever allocation for this tenant — create the
                // wallet row now, inside the same lock, rather than
                // requiring a separate "activate" step first.
                $account = AiCreditAccount::withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'balance' => 0,
                ]);
            }

            if (in_array($type, ['allocation', 'adjustment_credit'], true)) {
                $account->increment('balance', $amount);
            } else {
                $account->decrement('balance', $amount);
            }

            return AiUsageLedger::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'type' => $type,
                'credit_amount' => $amount,
                'balance_after' => $account->fresh()->balance,
                'note' => $note,
                'created_by' => $adminId,
            ]);
        });
    }
}
