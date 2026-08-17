<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Advertising\AdvertisingBalanceService;

/**
 * Read-only for tenants throughout — there is no store/update/destroy
 * method anywhere in this controller. A tenant can view their balance,
 * daily budget, rate, and history, but every write to those values is a
 * super-admin-only action (SuperAdmin\AdvertisingController). Every query
 * goes through AdvertisingBalanceService using the current tenant's own
 * ID, and every method starts by re-checking isEnabled() — the sidebar
 * link being hidden for a disabled tenant is not itself the security
 * boundary, this abort is.
 */
class AdvertisingController extends Controller
{
    public function __construct(protected AdvertisingBalanceService $service) {}

    protected function guard()
    {
        $tenant = app('currentTenant');

        abort_unless($this->service->isEnabled($tenant), 403, 'অ্যাডভার্টাইজিং মডিউলটি আপনার অ্যাকাউন্টে চালু নেই।');

        return $tenant;
    }

    public function overview()
    {
        $tenant = $this->guard();
        $account = $this->service->getAccount($tenant->id);

        return view('tenant.advertising.overview', [
            'account' => $account,
            'recent' => $this->service->ledger($tenant->id, perPage: 10, tenantVisibleOnly: true),
        ]);
    }

    public function ledger()
    {
        $tenant = $this->guard();

        return view('tenant.advertising.ledger', [
            'account' => $this->service->getAccount($tenant->id),
            'entries' => $this->service->ledger($tenant->id, tenantVisibleOnly: true),
        ]);
    }

    public function payments()
    {
        $tenant = $this->guard();

        return view('tenant.advertising.payments', [
            'account' => $this->service->getAccount($tenant->id),
            'entries' => $this->service->ledger($tenant->id, ['payment'], tenantVisibleOnly: true),
        ]);
    }

    public function charges()
    {
        $tenant = $this->guard();

        return view('tenant.advertising.charges', [
            'account' => $this->service->getAccount($tenant->id),
            'entries' => $this->service->ledger($tenant->id, ['charge', 'adjustment_debit'], tenantVisibleOnly: true),
        ]);
    }
}
