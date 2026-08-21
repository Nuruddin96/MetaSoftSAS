<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AdBillingLedger;
use App\Services\Advertising\AdvertisingBalanceService;
use Illuminate\Http\Request;

/**
 * Mirrors Tenant\AdvertisingController's real capability exactly — this is
 * the Ad Billing wallet module (tenant views their ad-spend balance, daily
 * budget, billing rate, and payment/charge history; every write is
 * super-admin-only, see AdvertisingBalanceService's docblock), NOT a Meta
 * Ads Manager campaign-creation integration — no such backend exists, so
 * campaigns/targeting/creative endpoints are correctly out of scope here.
 * Read-only, same as the web panel.
 */
class AdvertisingController extends Controller
{
    public function __construct(protected AdvertisingBalanceService $service) {}

    public function overview()
    {
        $tenant = app('currentTenant');

        if (! $this->service->isEnabled($tenant)) {
            return response()->json(['enabled' => false]);
        }

        $account = $this->service->getAccount($tenant->id);
        $recent = $this->service->ledger($tenant->id, perPage: 10, tenantVisibleOnly: true);

        return response()->json([
            'enabled' => true,
            'account' => $this->presentAccount($account),
            'recent' => collect($recent->items())->map(fn (AdBillingLedger $e) => $this->presentEntry($e)),
        ]);
    }

    public function ledger(Request $request)
    {
        $tenant = app('currentTenant');

        if (! $this->service->isEnabled($tenant)) {
            return response()->json(['enabled' => false, 'data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }

        $types = (array) $request->query('type', []);
        $entries = $this->service->ledger($tenant->id, $types, tenantVisibleOnly: true);

        return response()->json([
            'enabled' => true,
            'data' => collect($entries->items())->map(fn (AdBillingLedger $e) => $this->presentEntry($e)),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    protected function presentAccount($account): ?array
    {
        if (! $account) {
            return null;
        }

        $balance = (float) $account->balance;
        $status = $balance <= 0 ? 'suspended' : ($balance <= (float) $account->low_balance_threshold ? 'low' : 'active');

        return [
            'balance' => $balance,
            'daily_budget' => (float) $account->daily_budget,
            'billing_rate' => (float) $account->billing_rate,
            'low_balance_threshold' => (float) $account->low_balance_threshold,
            'status' => $status,
        ];
    }

    protected function presentEntry(AdBillingLedger $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'amount' => (float) $e->amount,
            'balance_after' => (float) $e->balance_after,
            'note' => $e->note,
            'created_at' => optional($e->created_at)->toIso8601String(),
        ];
    }
}
