<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppUpdateConfig;
use App\Models\Tenant;
use App\Models\TenantAppVersion;
use App\Models\TenantAppVersionHistory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-wise App Version Tracking console — read-only. The overview lists
 * EVERY tenant (never just the ones with a live report), each shown with
 * the single best evidence available about its app version, in priority
 * order:
 *   1. tenant_app_versions   — current-state upsert, evidence='current'
 *   2. tenant_app_version_history — evidence='historical'
 *   3. mobile_devices (Remote Support registration snapshot) — evidence='historical'
 *   4. nothing at all — evidence='unknown'
 * A historical-only row is deliberately never described as the tenant's
 * present state (see statusFor) — it's a last-known data point, not proof
 * of what the tenant is running today. Never writes to AppUpdateConfig
 * itself — that stays SuperAdmin\AppUpdateController's own, separate page.
 */
class TenantAppVersionController extends Controller
{
    public function index(Request $request)
    {
        $config = AppUpdateConfig::tablesReady() ? AppUpdateConfig::current() : null;

        $tenants = Tenant::query()
            ->select('id', 'store_name')
            ->when($request->q, fn ($q) => $q->where('store_name', 'like', '%'.$request->q.'%'))
            ->get();

        $currentByTenant = TenantAppVersion::tablesReady()
            ? TenantAppVersion::query()->get()->groupBy('tenant_id')->map(fn ($g) => $g->sortByDesc('last_seen_at')->first())
            : collect();

        $historyByTenant = TenantAppVersionHistory::tablesReady()
            ? TenantAppVersionHistory::query()->get()->groupBy('tenant_id')->map(fn ($g) => $g->sortByDesc('last_seen_at')->first())
            : collect();

        $devicesByTenant = Schema::hasTable('mobile_devices')
            ? DB::table('mobile_devices')
                ->select('tenant_id', 'app_version', 'device_model', 'os_version', 'platform', 'created_at')
                ->get()
                ->groupBy('tenant_id')
                ->map(fn ($g) => $g->sortByDesc('created_at')->first())
            : collect();

        $merged = $tenants->map(function (Tenant $tenant) use ($currentByTenant, $historyByTenant, $devicesByTenant) {
            return $this->evidenceForTenant($tenant, $currentByTenant, $historyByTenant, $devicesByTenant);
        });

        $merged = $merged
            ->when($request->build, fn ($c) => $c->filter(fn ($row) => $row->app_build == $request->build))
            ->when($request->version, fn ($c) => $c->filter(fn ($row) => $row->app_version === $request->version));

        // Distribution — every tenant with a known (version, build), from
        // either evidence tier, split into current-vs-historical counts so
        // "Build 12 — 3 tenants" is never confused with "3 tenants last
        // seen on Build 12 at some point in the past".
        $distribution = $merged
            ->filter(fn ($row) => $row->app_build !== null)
            ->groupBy(fn ($row) => $row->app_version.'+'.$row->app_build)
            ->map(fn ($group) => [
                'app_version' => $group->first()->app_version,
                'app_build' => $group->first()->app_build,
                'current_count' => $group->where('evidence', 'current')->count(),
                'historical_count' => $group->where('evidence', 'historical')->count(),
            ])
            ->sortByDesc('app_build')
            ->values();

        $withStatus = $merged->map(function ($row) use ($config) {
            $row->status = $this->statusFor($row, $config);

            return $row;
        });

        if ($request->status) {
            $withStatus = $withStatus->filter(fn ($row) => $row->status['label'] === $request->status)->values();
        }

        $withStatus = $request->sort === 'build'
            ? $withStatus->sortByDesc(fn ($row) => $row->app_build ?? -1)->values()
            : $withStatus->sortByDesc(fn ($row) => $row->last_seen_at?->timestamp ?? -1)->values();

        $perPage = 30;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $rows = new LengthAwarePaginator(
            $withStatus->forPage($page, $perPage)->values(),
            $withStatus->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('super.tenant-app-versions', [
            'rows' => $rows,
            'config' => $config,
            'distribution' => $distribution,
            'statusOptions' => ['Up to date', 'Update available', 'Below minimum supported', 'Older version — last known', 'Unknown'],
        ]);
    }

    /**
     * The single best evidence row for one tenant, tagged with which tier
     * it came from. Returns a plain object so the Blade view can use the
     * same `$row->tenant` / `$row->app_version` shape regardless of which
     * source it was built from.
     */
    private function evidenceForTenant(Tenant $tenant, $currentByTenant, $historyByTenant, $devicesByTenant): object
    {
        if ($current = $currentByTenant->get($tenant->id)) {
            return (object) [
                'tenant' => $tenant,
                'evidence' => 'current',
                'evidence_label' => 'Current report',
                'app_version' => $current->app_version,
                'app_build' => $current->app_build,
                'device_model' => $current->device_model,
                'os_version' => $current->os_version,
                'last_seen_at' => $current->last_seen_at,
            ];
        }

        if ($history = $historyByTenant->get($tenant->id)) {
            return (object) [
                'tenant' => $tenant,
                'evidence' => 'historical',
                'evidence_label' => 'Historical / Last known',
                'app_version' => $history->app_version,
                'app_build' => $history->app_build,
                'device_model' => $history->device_model,
                'os_version' => $history->os_version,
                'last_seen_at' => $history->last_seen_at,
            ];
        }

        if ($device = $devicesByTenant->get($tenant->id)) {
            [$version, $build] = str_contains($device->app_version, '+')
                ? explode('+', $device->app_version, 2)
                : [$device->app_version, null];

            return (object) [
                'tenant' => $tenant,
                'evidence' => 'historical',
                'evidence_label' => 'Historical / Last known',
                'app_version' => $version,
                'app_build' => $build !== null ? (int) $build : null,
                'device_model' => $device->device_model,
                'os_version' => $device->os_version,
                'last_seen_at' => Carbon::parse($device->created_at),
            ];
        }

        return (object) [
            'tenant' => $tenant,
            'evidence' => 'unknown',
            'evidence_label' => 'Unknown',
            'app_version' => null,
            'app_build' => null,
            'device_model' => null,
            'os_version' => null,
            'last_seen_at' => null,
        ];
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

    /**
     * @param  TenantAppVersion|TenantAppVersionHistory|object  $row
     *
     * A row from the overview's merged evidence (see evidenceForTenant)
     * carries an `evidence` tag and is never described as the tenant's
     * present state when that tag is 'historical' or 'unknown' — only a
     * 'current' (or untagged, i.e. a plain TenantAppVersion/History model
     * from the per-tenant show() page, unchanged from before) row gets
     * compared against AppUpdateConfig at all.
     */
    private function statusFor($row, ?AppUpdateConfig $config): array
    {
        $evidence = $row->evidence ?? null;

        if ($evidence === 'unknown') {
            return ['label' => 'Unknown', 'class' => 'bg-ink/5 text-mute'];
        }
        if ($evidence === 'historical') {
            return ['label' => 'Older version — last known', 'class' => 'bg-amber/20 text-amber-800'];
        }

        if (! $config || $config->latest_build === null || $row->app_build === null) {
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
