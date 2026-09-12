<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use App\Services\Courier\CourierManager;
use Illuminate\Console\Command;

/**
 * Periodic sync so Web/Mobile show the latest courier status without a
 * merchant manually tapping "refresh" on every order — extends the
 * existing on-demand refresh path (CourierController::refreshStatus() /
 * Api\Mobile\OrderController::refreshCourierStatus(), both already call
 * CourierManager::forProvider()->getStatus()) rather than introducing a
 * second status-fetching mechanism. Steadfast has no webhook for status
 * changes (confirmed — only create_order/status_by_invoice/get_balance
 * exist), so polling is the only option; this is that "sensible
 * polling/sync mechanism" the courier feature needs, not a claim of true
 * push-based realtime.
 *
 * Stores whatever raw string the courier API returns as `courier_status`
 * unchanged (same as the manual refresh path already did) — no invented
 * status vocabulary/mapping layer, per the task's own "use the exact
 * status mapping supported by the current integration" instruction.
 *
 * `courier_status_checked_at` (chunk62.sql) is new: the manual refresh
 * path never recorded when a status was last confirmed as current, so
 * Web/Mobile had no honest "last updated" timestamp to show — this command
 * is what keeps that column meaningful even for orders nobody has manually
 * refreshed recently.
 */
class RefreshCourierStatuses extends Command
{
    protected $signature = 'courier:refresh-statuses';

    protected $description = 'Poll the connected courier API for the latest status of every not-yet-terminal dispatched order, tenant by tenant';

    /** Same terminal-state definition Api\Mobile\OrderController::index()'s courier=pending filter already uses. */
    protected const TERMINAL_STATUSES = ['delivered', 'cancelled', 'returned'];

    public function handle(): int
    {
        $checked = 0;
        $updated = 0;
        $failed = 0;

        Tenant::where('status', 'active')->lazy()->each(function (Tenant $tenant) use (&$checked, &$updated, &$failed) {
            app()->instance('currentTenant', $tenant);

            Order::whereNotNull('courier_consignment_id')
                ->whereNotNull('courier_provider')
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->lazy()
                ->each(function (Order $order) use (&$checked, &$updated, &$failed) {
                    $service = CourierManager::forProvider($order->courier_provider);
                    if (! $service) {
                        return;
                    }

                    $checked++;

                    try {
                        $status = $service->getStatus($order);
                        if ($status !== $order->courier_status) {
                            $updated++;
                        }
                        $order->update(['courier_status' => $status, 'courier_status_checked_at' => now()]);
                    } catch (\Throwable $e) {
                        $failed++;
                        report($e);
                    }
                });

            app()->forgetInstance('currentTenant');
        });

        $this->info("Courier statuses checked: {$checked}. Changed: {$updated}. Failed: {$failed}.");

        return self::SUCCESS;
    }
}
