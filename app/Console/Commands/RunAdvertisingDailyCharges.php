<?php

namespace App\Console\Commands;

use App\Models\AdBillingAccount;
use App\Models\AdBillingLedger;
use App\Services\Advertising\AdvertisingBalanceService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Applies each active tenant's daily_budget as a 'charge' ledger entry for
 * "today" in Asia/Dhaka. Meant to run once daily via Hostinger Cron Jobs
 * (see config('advertising.timezone')) — never triggered by a page render.
 *
 * Idempotent per tenant per Dhaka calendar day: ad_billing_ledger has a
 * UNIQUE(tenant_id, charge_date) index, so re-running this command the
 * same day (a retried/overlapping cron fire) is a no-op for tenants
 * already charged, same defensive pattern as the Messenger webhook's mid
 * dedup (check first, then let the unique constraint be the final guard).
 */
class RunAdvertisingDailyCharges extends Command
{
    protected $signature = 'advertising:run-daily-charges';

    protected $description = 'Apply the daily budget charge for every active Advertising account (Asia/Dhaka calendar day)';

    public function handle(AdvertisingBalanceService $service): int
    {
        if (! AdBillingAccount::tablesReady()) {
            $this->error('ad_billing_accounts/ad_billing_ledger not found — import database/sql/chunk29.sql first.');

            return self::FAILURE;
        }

        $today = $service->today();
        $charged = 0;
        $skipped = 0;

        AdBillingAccount::withoutGlobalScopes()
            ->where('is_active', 1)
            ->where('daily_budget', '>', 0)
            ->lazy()
            ->each(function (AdBillingAccount $account) use ($service, $today, &$charged, &$skipped) {
                $alreadyCharged = AdBillingLedger::withoutGlobalScopes()
                    ->where('tenant_id', $account->tenant_id)
                    ->where('charge_date', $today->toDateString())
                    ->exists();

                if ($alreadyCharged) {
                    $skipped++;

                    return;
                }

                try {
                    $service->recordCharge(
                        $account->tenant_id,
                        (float) $account->daily_budget,
                        note: 'দৈনিক চার্জ (স্বয়ংক্রিয়)',
                        chargeDate: $today,
                    );
                    $charged++;
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23000') {
                        throw $e;
                    }
                    // Race: another run already inserted today's charge_date
                    // for this tenant between the exists() check and here.
                    $skipped++;
                }
            });

        $this->info("Daily charges applied: {$charged}. Already charged today: {$skipped}.");

        return self::SUCCESS;
    }
}
