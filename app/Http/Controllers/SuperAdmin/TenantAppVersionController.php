<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppUpdateConfig;
use App\Models\TenantAppVersion;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Tenant-wise App Version Tracking console — read-only. Shows which build
 * each tenant's Business App login last reported (see
 * Api\Mobile\TenantAppVersionController::report()), with a descriptive
 * status computed against AppUpdateConfig's EXISTING values. Never writes
 * to AppUpdateConfig itself — that stays SuperAdmin\AppUpdateController's
 * own, separate page.
 */
class TenantAppVersionController extends Controller
{
    public function index(Request $request)
    {
        $config = AppUpdateConfig::tablesReady() ? AppUpdateConfig::current() : null;

        $statusFor = function (TenantAppVersion $row) use ($config): array {
            if (! $config || $config->latest_build === null) {
                return ['label' => 'Unknown', 'class' => 'bg-ink/5 text-mute'];
            }
            if ($config->minimum_supported_build !== null && $row->app_build < $config->minimum_supported_build) {
                return ['label' => 'Below minimum supported', 'class' => 'bg-red-100 text-red-700'];
            }
            if ($row->app_build < $config->latest_build) {
                return ['label' => 'Update available', 'class' => 'bg-amber/20 text-amber-800'];
            }

            return ['label' => 'Up to date', 'class' => 'bg-leaf/10 text-leafdk'];
        };

        $rows = null;
        if (TenantAppVersion::tablesReady()) {
            // status isn't a stored column (it's computed from two
            // AppUpdateConfig values together), so it can't be a plain SQL
            // WHERE — every DB-filterable condition (tenant/build/version)
            // runs in SQL first, then status is computed and optionally
            // filtered in PHP, THEN paginated by hand so the page meta
            // (total/last page) matches what's actually being shown rather
            // than a raw-SQL page sliced before the status filter ran.
            $filtered = TenantAppVersion::query()
                ->with(['tenant:id,store_name', 'user:id,name'])
                ->when($request->q, fn ($q) => $q->whereHas('tenant', fn ($t) => $t->where('store_name', 'like', '%'.$request->q.'%')))
                ->when($request->build, fn ($q) => $q->where('app_build', $request->build))
                ->when($request->version, fn ($q) => $q->where('app_version', $request->version))
                ->when($request->sort === 'build', fn ($q) => $q->orderByDesc('app_build'), fn ($q) => $q->orderByDesc('last_seen_at'))
                ->get();

            if ($request->status) {
                $filtered = $filtered->filter(fn ($row) => $statusFor($row)['label'] === $request->status)->values();
            }

            $perPage = 30;
            $page = LengthAwarePaginator::resolveCurrentPage();
            $rows = new LengthAwarePaginator(
                $filtered->forPage($page, $perPage)->values(),
                $filtered->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        return view('super.tenant-app-versions', [
            'rows' => $rows,
            'config' => $config,
            'statusFor' => $statusFor,
            'statusOptions' => ['Up to date', 'Update available', 'Below minimum supported', 'Unknown'],
        ]);
    }
}
