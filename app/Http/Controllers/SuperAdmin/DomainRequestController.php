<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;

/**
 * Cross-tenant "Custom Domain Requests" management list. Per-tenant
 * domain actions (approve/activate/deactivate/reject/delete) already live
 * on super.tenants.show (see TenantController's domain methods) — this is
 * purely a read view listing every tenant that has ever requested or
 * activated a custom domain on one screen, so staff don't need to open
 * every tenant individually to find what needs review.
 */
class DomainRequestController extends Controller
{
    public function index()
    {
        $tenants = Tenant::query()
            ->where(function ($q) {
                $q->whereNotNull('custom_domain_requested')
                    ->orWhereNotNull('custom_domain')
                    ->orWhere('custom_domain_request_status', '!=', 'none');
            })
            ->orderByDesc('updated_at')
            ->paginate(25);

        return view('super.domain-requests', compact('tenants'));
    }
}
