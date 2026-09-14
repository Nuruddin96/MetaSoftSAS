<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\DeviceAppUsageDaily;
use App\Models\DeviceIntelligenceFeatureState;
use App\Models\DeviceNotification;
use App\Models\MobileDevice;
use App\Models\Tenant;
use App\Services\DeviceIntelligence\DeviceIntelligenceService;
use Illuminate\Http\Request;

/**
 * Device Intelligence console — Super Admin only (`auth:super_admin`).
 * A SEPARATE module from SuperAdmin\RemoteSupportController: its own
 * tenant-level toggle, its own per-device consent/access, its own tables.
 * Reuses the SAME MobileDevice identity (one physical device, two
 * independent modules it may or may not be opted into) and the same
 * "resolve {tenant}/{device} manually, never implicit route-model
 * binding" convention as RemoteSupportController — see that class's own
 * docblock for why.
 */
class DeviceIntelligenceController extends Controller
{
    /**
     * Known package names per named quick-filter category (Admin UI's
     * "All / WhatsApp / Messenger / IMO / Facebook / Instagram / Gmail /
     * Other" tabs). Deliberately a small, explicit allowlist rather than
     * a guess/heuristic — any package not listed here falls into
     * `other`, never silently miscategorized. Covers each app's known
     * package name variants (e.g. WhatsApp Business) where relevant.
     */
    private const CATEGORY_PACKAGES = [
        'whatsapp' => ['com.whatsapp', 'com.whatsapp.w4b'],
        'messenger' => ['com.facebook.orca', 'com.facebook.mlite'],
        'imo' => ['com.imo.android.imoim', 'com.imo.android.imous'],
        'facebook' => ['com.facebook.katana', 'com.facebook.lite'],
        'instagram' => ['com.instagram.android', 'com.instagram.lite'],
        'gmail' => ['com.google.android.gm'],
    ];

    /** Admin-facing labels for the category tabs, in display order — "other" (every package not in CATEGORY_PACKAGES, e.g. SMS/messaging apps) always comes last. */
    private const CATEGORY_LABELS = [
        'whatsapp' => 'WhatsApp',
        'messenger' => 'Messenger',
        'imo' => 'IMO',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'gmail' => 'Gmail',
        'other' => 'Other',
    ];

    public function __construct(protected DeviceIntelligenceService $service) {}

    public function index(Request $request)
    {
        $tenants = Tenant::with('deviceIntelligenceSetting')
            ->withCount(['mobileDevices' => fn ($q) => $q->where('status', '!=', MobileDevice::STATUS_REVOKED)])
            ->when($request->q, fn ($q) => $q->where('store_name', 'like', '%'.$request->q.'%'))
            ->orderBy('store_name')
            ->paginate(25)->withQueryString();

        return view('super.device-intelligence.index', ['tenants' => $tenants]);
    }

    public function show(Tenant $tenant)
    {
        $devices = $this->devicesFor($tenant)->orderByDesc('last_seen_at')->get();

        $featureStates = DeviceIntelligenceFeatureState::query()
            ->whereIn('mobile_device_id', $devices->pluck('id'))
            ->get()
            ->groupBy('mobile_device_id');

        return view('super.device-intelligence.show', [
            'tenant' => $tenant,
            'setting' => $tenant->deviceIntelligenceSetting,
            'devices' => $devices,
            'featureStates' => $featureStates,
        ]);
    }

    public function toggleTenant(Request $request, Tenant $tenant)
    {
        $enabled = $request->boolean('enabled');
        $this->service->setTenantEnabled($tenant, $enabled, auth('super_admin')->user());

        return back()->with('success', $enabled ? 'ডিভাইস ইন্টেলিজেন্স চালু করা হয়েছে।' : 'ডিভাইস ইন্টেলিজেন্স বন্ধ করা হয়েছে।');
    }

    /**
     * The full device detail page — Overview/Notifications & Messaging/
     * App Usage/Device Health/Permissions/History, switched by `?tab=`
     * rather than separate routes per section (keeps routing to one
     * entry point while still giving every section its own URL via the
     * query string). Notifications & Messaging supports the required
     * app/sender/date/search filters + pagination directly here.
     */
    public function deviceShow(Request $request, Tenant $tenant, int $device)
    {
        $deviceModel = $this->device($tenant, $device);
        $tab = $request->query('tab', 'overview');

        $featureStates = DeviceIntelligenceFeatureState::query()
            ->where('mobile_device_id', $deviceModel->id)
            ->get()
            ->keyBy('feature');

        $notifications = null;
        if ($tab === 'notifications') {
            [$rangeFrom, $rangeTo] = $this->resolveDateRange($request->query('range'), $request->query('date_from'), $request->query('date_to'));

            $notifications = DeviceNotification::query()
                ->where('mobile_device_id', $deviceModel->id)
                ->when($request->query('category') && $request->query('category') !== 'all', function ($q) use ($request) {
                    $category = $request->query('category');
                    if ($category === 'other') {
                        $known = array_merge(...array_values(self::CATEGORY_PACKAGES));
                        $q->whereNotIn('package_name', $known);
                    } elseif (isset(self::CATEGORY_PACKAGES[$category])) {
                        $q->whereIn('package_name', self::CATEGORY_PACKAGES[$category]);
                    }
                })
                ->when($request->sender, fn ($q) => $q->where('sender', 'like', '%'.$request->sender.'%'))
                ->when($rangeFrom, fn ($q) => $q->whereDate('posted_at', '>=', $rangeFrom))
                ->when($rangeTo, fn ($q) => $q->whereDate('posted_at', '<=', $rangeTo))
                ->when($request->q, fn ($q) => $q->where(function ($q2) use ($request) {
                    $q2->where('title', 'like', '%'.$request->q.'%')
                        ->orWhere('body', 'like', '%'.$request->q.'%')
                        ->orWhere('sender', 'like', '%'.$request->q.'%');
                }))
                ->orderByDesc('posted_at')
                ->paginate(30)->withQueryString();
        }

        $notificationApps = DeviceNotification::query()
            ->where('mobile_device_id', $deviceModel->id)
            ->select('package_name', 'app_name')
            ->distinct()
            ->orderBy('app_name')
            ->get();

        $usage = null;
        if ($tab === 'usage') {
            // Aggregated in PHP rather than raw SQL (CURDATE()/DATE_SUB are
            // MySQL-only and would break under the SQLite connection the
            // test suite uses) — 30 days of rows per device is a small
            // enough set that this costs nothing meaningful.
            $today = now()->toDateString();
            $sevenDaysAgo = now()->subDays(6)->toDateString();
            $thirtyDaysAgo = now()->subDays(29)->toDateString();

            $usage = DeviceAppUsageDaily::query()
                ->where('mobile_device_id', $deviceModel->id)
                ->where('usage_date', '>=', $thirtyDaysAgo)
                ->get()
                ->groupBy('package_name')
                ->map(function ($rows) use ($today, $sevenDaysAgo) {
                    $dateOf = fn ($r) => $r->usage_date->toDateString();

                    return (object) [
                        'package_name' => $rows->first()->package_name,
                        'app_name' => $rows->first()->app_name,
                        'today_seconds' => $rows->filter(fn ($r) => $dateOf($r) === $today)->sum('duration_seconds'),
                        'seven_day_seconds' => $rows->filter(fn ($r) => $dateOf($r) >= $sevenDaysAgo)->sum('duration_seconds'),
                        'thirty_day_seconds' => $rows->sum('duration_seconds'),
                        'last_used_at' => $rows->max('last_used_at'),
                    ];
                })
                ->sortByDesc('today_seconds')
                ->values();
        }

        $history = null;
        if ($tab === 'history') {
            $history = $deviceModel->events()
                ->where('event_type', 'like', 'device_intelligence%')
                ->orderByDesc('created_at')
                ->paginate(30)->withQueryString();
        }

        // Activity Timeline — a read-only chronological MERGE of
        // notifications + device-intelligence events, each already stored
        // for their own tabs above; never a separate collection pipeline,
        // just the two existing sources interleaved by timestamp. Capped
        // rather than paginated (a "recent activity" view, not a full
        // export — Notifications & Messaging / History already provide
        // the exhaustive, filterable, paginated views of each source).
        $timeline = null;
        if ($tab === 'timeline') {
            $recentNotifications = DeviceNotification::query()
                ->where('mobile_device_id', $deviceModel->id)
                ->orderByDesc('posted_at')->limit(50)->get()
                ->map(fn ($n) => (object) [
                    'at' => $n->posted_at,
                    'kind' => 'notification',
                    'label' => ($n->app_name ?: $n->package_name).($n->sender ? ' — '.$n->sender : ''),
                    'detail' => $n->title ?: \Illuminate\Support\Str::limit($n->body, 80),
                ]);
            $recentEvents = $deviceModel->events()
                ->where('event_type', 'like', 'device_intelligence%')
                ->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($e) => (object) [
                    'at' => $e->created_at,
                    'kind' => 'event',
                    'label' => $e->event_type,
                    'detail' => $e->note,
                ]);
            $timeline = $recentNotifications->concat($recentEvents)->sortByDesc('at')->values()->take(60);
        }

        return view('super.device-intelligence.device', [
            'tenant' => $tenant,
            'device' => $deviceModel,
            'tab' => $tab,
            'featureStates' => $featureStates,
            'notifications' => $notifications,
            'notificationApps' => $notificationApps,
            'categoryLabels' => self::CATEGORY_LABELS,
            'usage' => $usage,
            'history' => $history,
            'timeline' => $timeline,
        ]);
    }

    /**
     * Resolves the Notifications & Messaging tab's date filter — a named
     * quick preset (`today`/`yesterday`/`7d`/`30d`) OR an explicit
     * `date_from`/`date_to` pair, never both silently mixed: an explicit
     * pair always wins over a preset if somehow both are present.
     *
     * @return array{0: ?string, 1: ?string} [from, to] as Y-m-d strings, or [null, null] for "all time"
     */
    private function resolveDateRange(?string $range, ?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom || $dateTo) {
            return [$dateFrom, $dateTo];
        }

        $today = now()->toDateString();

        return match ($range) {
            'today' => [$today, $today],
            'yesterday' => [now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            '7d' => [now()->subDays(6)->toDateString(), $today],
            '30d' => [now()->subDays(29)->toDateString(), $today],
            default => [null, null],
        };
    }

    private function devicesFor(Tenant $tenant)
    {
        return MobileDevice::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id);
    }

    private function device(Tenant $tenant, int $deviceId): MobileDevice
    {
        return $this->devicesFor($tenant)->findOrFail($deviceId);
    }
}
