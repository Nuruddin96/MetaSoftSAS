<?php

namespace App\Services\Advertising;

use App\Models\AdBillingAccount;
use App\Models\AdBillingLedger;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for the Advertising module's balance. Every
 * balance mutation (tenant payment, admin charge/credit/debit, the daily
 * charge job) goes through here, and every reader (header chip, tenant
 * Overview/Ledger pages, admin module) reads through here — so the
 * balance is never computed or written ad-hoc in a controller or view.
 *
 * tenant_id is always explicit and every query uses withoutGlobalScopes(),
 * because this service is called both from a tenant-bound request (header,
 * tenant module) and from super-admin / console contexts where
 * app('currentTenant') is never bound (see BelongsToTenant) — relying on
 * the ambient global scope here would silently return unscoped data in
 * the admin/console case instead of failing loudly.
 */
class AdvertisingBalanceService
{
    /**
     * The full module-activation gate: the ad_billing_* tables are
     * imported AND the tenant's plan allows it AND an account row exists
     * for them AND that row is switched on. Every sidebar item, header
     * chip, and route guard in the module uses this single check.
     */
    public function isEnabled(Tenant $tenant): bool
    {
        if (! AdBillingAccount::tablesReady()) {
            return false;
        }

        if (! $tenant->plan?->allow_meta_ads) {
            return false;
        }

        $account = $this->getAccount($tenant->id);

        return (bool) $account?->is_active;
    }

    public function getAccount(int $tenantId): ?AdBillingAccount
    {
        if (! AdBillingAccount::tablesReady()) {
            return null;
        }

        return AdBillingAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * Cached tenant-facing balance — a single indexed row read, never a
     * ledger SUM. Returns null when the tenant has no account at all
     * (caller should treat that as "module not enabled", not as ৳0).
     */
    public function balance(int $tenantId): ?string
    {
        return $this->getAccount($tenantId)?->balance;
    }

    /**
     * Creates the account row that switches the module on for a tenant
     * (super-admin action only). Idempotent: returns the existing account
     * unchanged if one is already there. Starts at balance = 0 (the
     * column's DB default) — activation never migrates or assumes a prior
     * balance; add an initial payment/adjustment separately if needed.
     */
    public function activate(int $tenantId, float $dailyBudget, float $billingRate, float $lowBalanceThreshold = 0): AdBillingAccount
    {
        return AdBillingAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'daily_budget' => $dailyBudget,
                'billing_rate' => $billingRate,
                'low_balance_threshold' => $lowBalanceThreshold,
                'is_active' => 1,
            ]
        );
    }

    /** Super-admin: update settings a tenant is never allowed to touch themselves. */
    public function updateSettings(int $tenantId, array $data): void
    {
        AdBillingAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->update(array_intersect_key(
            $data,
            array_flip(['daily_budget', 'billing_rate', 'low_balance_threshold', 'is_active'])
        ));
    }

    /** Tenant payment received — increases the tenant-facing balance. */
    public function recordPayment(int $tenantId, float $amount, ?string $note = null, ?int $adminId = null): AdBillingLedger
    {
        return $this->applyEntry($tenantId, 'payment', $amount, $note, adminId: $adminId);
    }

    /**
     * A charge against the tenant's balance — either a manual admin entry
     * or the automated daily charge (see RunAdvertisingDailyCharges).
     * $metaSpendUsd is admin-only cost data recorded alongside the
     * tenant-facing BDT amount; it is never read by any tenant-facing code.
     */
    public function recordCharge(
        int $tenantId,
        float $amountBdt,
        ?float $metaSpendUsd = null,
        ?string $note = null,
        ?\DateTimeInterface $chargeDate = null,
        ?int $adminId = null
    ): AdBillingLedger {
        return $this->applyEntry($tenantId, 'charge', $amountBdt, $note, metaSpendUsd: $metaSpendUsd, chargeDate: $chargeDate, adminId: $adminId);
    }

    /** Manual admin balance correction, either direction. */
    public function recordAdjustment(int $tenantId, float $amount, string $direction, string $note, int $adminId): AdBillingLedger
    {
        $type = $direction === 'credit' ? 'adjustment_credit' : 'adjustment_debit';

        return $this->applyEntry($tenantId, $type, $amount, $note, adminId: $adminId);
    }

    /**
     * Paginated ledger for one tenant, optionally filtered by type(s).
     * $tenantVisibleOnly strips meta_spend_usd/charge_date/created_by
     * before the caller ever sees them — pass true from every tenant-
     * facing controller. This is a SQL-level select() restriction, not
     * merely hiding fields in Blade.
     */
    public function ledger(int $tenantId, array $types = [], int $perPage = 30, bool $tenantVisibleOnly = false)
    {
        $query = AdBillingLedger::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($types, fn ($q) => $q->whereIn('type', $types))
            ->orderByDesc('id');

        if ($tenantVisibleOnly) {
            $query->select(AdBillingLedger::TENANT_VISIBLE_COLUMNS);
        } else {
            $query->with('admin');
        }

        return $query->paginate($perPage);
    }

    /** "Today" for day-boundary/daily-charge purposes — always Asia/Dhaka, never server/app timezone. */
    public function today(): Carbon
    {
        return Carbon::now(config('advertising.timezone'))->startOfDay();
    }

    /**
     * Core, single write path — every public mutator above funnels through
     * here. Locks the account row for the duration of the transaction
     * (same lockForUpdate()-for-money-math pattern already used by Order's
     * sequential numbering) and mutates balance via Eloquent's atomic
     * increment()/decrement() — the same approach DueLedger already uses
     * for the customer-due ledger — rather than read-modify-write.
     */
    protected function applyEntry(
        int $tenantId,
        string $type,
        float $amount,
        ?string $note,
        ?float $metaSpendUsd = null,
        ?\DateTimeInterface $chargeDate = null,
        ?int $adminId = null
    ): AdBillingLedger {
        return DB::transaction(function () use ($tenantId, $type, $amount, $note, $metaSpendUsd, $chargeDate, $adminId) {
            $account = AdBillingAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($type, ['payment', 'adjustment_credit'], true)) {
                $account->increment('balance', $amount);
            } else {
                $account->decrement('balance', $amount);
            }

            return AdBillingLedger::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $account->fresh()->balance,
                'meta_spend_usd' => $metaSpendUsd,
                'charge_date' => $chargeDate?->format('Y-m-d'),
                'note' => $note,
                'created_by' => $adminId,
            ]);
        });
    }
}
