<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppUpdateConfig;
use App\Models\Tenant;
use App\Models\TenantAppVersion;
use App\Models\TenantAppVersionHistory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Tenant-wise App Version Tracking console — read-only. Shows which build
 * each tenant's Business App login last reported (see
 * Api\Mobile\TenantAppVersionController::report()), a version-distribution
 * summary, and (per tenant) the version-change history that same endpoint
 * also writes. Status is a descriptive label computed against
 * AppUpdateConfig's EXISTING values. Never writes to AppUpdateConfig
 * itself — that stays SuperAdmin\AppUpdateController's own, separate page.
 */
class TenantAppVersionController extends Controller
{
    public function index(Request $request)
    {
        $config = AppUpdateConfig::tablesReady() ? AppUpdateConfig::current() : null;

        $rows = null;
        $distribution = collect();
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

            // Distribution summary — always computed from the FULL
            // (unfiltered-by-status) current-version set, never the
            // filtered/paginated page, so "Build 12 — 4 tenants" stays
            // accurate regardless of what filter the admin currently has
            // applied to the table below it. Only counts rows that
            // genuinely exist (real reports) — never a synthesized "0 for
            // every other build" entry.
            $distribution = $filtered
                ->groupBy(fn ($row) => $row->app_version.'+'.$row->app_build)
                ->map(fn ($group) => [
                    'app_version' => $group->first()->app_version,
                    'app_build' => $group->first()->app_build,
                    'count' => $group->count(),
                ])
                ->sortByDesc('app_build')
                ->values();

            if ($request->status) {
                $filtered = $filtered->filter(fn ($row) => $this->statusFor($row, $config)['label'] === $request->status)->values();
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
            'distribution' => $distribution,
            'statusFor' => fn (TenantAppVersion $row) => $this->statusFor($row, $config),
            'statusOptions' => ['Up to date', 'Update available', 'Below minimum supported', 'Unknown'],
        ]);
    }

    /**
     * One tenant's full version-change history — every row
     * Api\Mobile\TenantAppVersionController::report() (or the one-off
     * historical backfill, see TenantAppVersionHistory's own doc
     * comment) has ever recorded for this tenant, oldest first.
     */
    public function show(Tenant $tenant)
    {
        $config = AppUpdateConfig::tablesReady() ? AppUpdateConfig::current() : null;

        $current = TenantAppVersion::tablesReady()
            ? TenantAppVersion::withoutGlobalScopes()->with('user:id,name')->where('tenant_id', $tenant->id)->get()
            : collect();

        $history = TenantAppVersionHistory::tablesReady()
            ? TenantAppVersionHistory::withoutGlobalScopes()->with('user:id,name')->where('tenant_id', $tenant->id)->orderBy('first_seen_at')->get()
            : collect();

        return view('super.tenant-app-version-history', [
            'tenant' => $tenant,
            'config' => $config,
            'current' => $current,
            'history' => $history,
            'statusFor' => fn ($row) => $this->statusFor($row, $config),
        ]);
    }

    /** @param  TenantAppVersion|TenantAppVersionHistory  $row */
    private function statusFor($row, ?AppUpdateConfig $config): array
    {
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
    }
}
