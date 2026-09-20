<?php

namespace App\Services\DeviceIntelligence;

use App\Models\DeviceAppUsageDaily;
use App\Models\DeviceEvent;
use App\Models\DeviceIntelligenceFeatureState;
use App\Models\DeviceIntelligenceSetting;
use App\Models\DeviceNotification;
use App\Models\MobileDevice;
use App\Models\PermissionRequest;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Services\PermissionRequestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single place every Device Intelligence state mutation goes through —
 * tenant enable/disable, per-feature consent/access sync, telemetry sync,
 * and notification/usage ingestion — mirroring RemoteSupportService's own
 * "controllers never write to these tables directly" convention. A
 * DELIBERATELY SEPARATE module from Remote Support: reuses the same
 * MobileDevice identity/credential (see the migration docblocks — "do not
 * create a duplicate device identity system"), but every table, setting,
 * and consent flag here is its own, never merged with Remote Support's.
 */
class DeviceIntelligenceService
{
    public function __construct(protected PermissionRequestService $permissionRequests) {}

    public function setTenantEnabled(Tenant $tenant, bool $enabled, SuperAdmin $admin): DeviceIntelligenceSetting
    {
        return DB::transaction(function () use ($tenant, $enabled, $admin) {
            $setting = DeviceIntelligenceSetting::firstOrNew(['tenant_id' => $tenant->id]);

            $setting->enabled = $enabled;
            if ($enabled) {
                $setting->enabled_by_super_admin_id = $admin->id;
                $setting->enabled_at = now();
            } else {
                $setting->disabled_by_super_admin_id = $admin->id;
                $setting->disabled_at = now();
            }
            $setting->save();

            $this->log($tenant->id, null, $enabled ? 'device_intelligence_enabled' : 'device_intelligence_disabled', 'super_admin', $admin->id);

            return $setting;
        });
    }

    /**
     * Same stale/out-of-order hardening as
     * RemoteSupportService::syncConsentState() (see that method's doc
     * comment for the full rationale) — `observed_at` is the moment the
     * Flutter app captured this feature's snapshot; a request whose
     * `observed_at` is not strictly newer than the currently stored
     * `state_observed_at` is ignored (except `access_synced_at`, which
     * always advances). `activation_status` is always recomputed
     * server-side via DeviceIntelligenceFeatureState::
     * computeActivationStatus(), never trusted from the client.
     */
    public function syncFeatureState(MobileDevice $device, string $feature, array $data): DeviceIntelligenceFeatureState
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);
        abort_unless(array_key_exists($feature, DeviceIntelligenceFeatureState::REQUIRED_ACCESS_KEYS), 422, 'Unknown feature.');

        return DB::transaction(function () use ($device, $feature, $data) {
            $state = DeviceIntelligenceFeatureState::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['mobile_device_id' => $device->id, 'feature' => $feature],
                    ['tenant_id' => $device->tenant_id],
                );

            $observedAt = isset($data['observed_at']) ? Carbon::parse($data['observed_at']) : null;
            $isStale = $observedAt !== null && $state->state_observed_at !== null && ! $observedAt->gt($state->state_observed_at);

            $state->access_synced_at = now();

            if ($isStale) {
                $state->save();

                $this->log($device->tenant_id, $device->id, 'device_intelligence_sync_stale_ignored', 'device', null, json_encode(['feature' => $feature]));

                return $state;
            }

            $newConsent = $data['app_consent_status'] ?? $state->app_consent_status;
            $newAccess = array_key_exists('android_access', $data) ? $data['android_access'] : ($state->android_access ?? []);

            $consentChanged = $newConsent !== $state->app_consent_status;
            $accessChanged = $newAccess !== ($state->android_access ?? []);

            $newActivation = DeviceIntelligenceFeatureState::computeActivationStatus($feature, $newConsent, $newAccess);

            $state->app_consent_status = $newConsent;
            $state->android_access = $newAccess;
            $state->activation_status = $newActivation;
            if ($observedAt !== null) {
                $state->state_observed_at = $observedAt;
            }
            if ($consentChanged) {
                $state->consent_changed_at = now();
            }
            if ($newActivation === \App\Support\DeviceAccessActivation::ACTIVATION_ACTIVE) {
                $state->last_active_at = now();
            }

            $state->save();

            if ($consentChanged || $accessChanged) {
                $this->log(
                    $device->tenant_id,
                    $device->id,
                    'device_intelligence_feature_synced',
                    'device',
                    null,
                    json_encode(['feature' => $feature, 'app_consent_status' => $newConsent, 'activation_status' => $newActivation]),
                );
            }

            return $state;
        });
    }

    /**
     * Queues a ONE-TIME "please fetch your current location now" —
     * never continuous tracking (see class-level "do not silently
     * enable tracking" principle this whole module already follows for
     * `location`). Picked up by the device's existing
     * DeviceIntelligenceSyncWorker poll (see
     * Api\Mobile\DeviceIntelligenceController::locationPending) —
     * reusing that existing transport, never a new one. Firmly a no-op
     * if the tenant never consented to the `location` feature at all
     * (there is nothing to fetch); the device still answers honestly
     * either way (see reportLocation()).
     */
    public function requestLocationFetch(MobileDevice $device, SuperAdmin $admin): DeviceIntelligenceFeatureState
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);

        // Unified lifecycle/history row — additive only, see
        // PermissionRequestService::create()'s doc comment. This is the
        // ONLY change to this method's existing behavior: the
        // pending_location_fetch_requested_at flag below (what the
        // device's own poll actually checks) is set exactly as before,
        // unconditionally. The 409 this can throw ("already pending") is
        // deliberately allowed to propagate — SuperAdmin\
        // DeviceIntelligenceController::requestLocation() has no
        // try/catch today because a location request was never
        // rate-limited before; now that it can be, that controller
        // catches it the same way DevicePermissionController does.
        $this->permissionRequests->create($device, PermissionRequest::CAPABILITY_LOCATION, $admin);

        $state = DeviceIntelligenceFeatureState::query()->firstOrCreate(
            ['mobile_device_id' => $device->id, 'feature' => DeviceIntelligenceFeatureState::FEATURE_LOCATION],
            ['tenant_id' => $device->tenant_id],
        );
        $state->pending_location_fetch_requested_at = now();
        $state->save();

        $this->log($device->tenant_id, $device->id, 'device_intelligence_location_fetch_requested', 'super_admin', $admin->id);

        return $state;
    }

    /**
     * The device-side report for requestLocationFetch() above — always
     * called, even when the fetch failed/was denied (see this method's
     * `$data['status']` handling), so Super Admin sees a real, honest
     * outcome rather than a request that silently never resolves.
     * Coordinates are ONLY ever stored here, as a single "last known"
     * snapshot overwritten by each new on-demand fetch — never a
     * history/track log.
     */
    public function reportLocation(MobileDevice $device, array $data): DeviceIntelligenceFeatureState
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);

        $state = DeviceIntelligenceFeatureState::query()->firstOrCreate(
            ['mobile_device_id' => $device->id, 'feature' => DeviceIntelligenceFeatureState::FEATURE_LOCATION],
            ['tenant_id' => $device->tenant_id],
        );

        $state->pending_location_fetch_requested_at = null;
        $state->last_location = [
            'status' => $data['status'],
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'captured_at' => (isset($data['captured_at']) ? Carbon::parse($data['captured_at']) : now())->toIso8601String(),
        ];
        $state->save();

        $this->log($device->tenant_id, $device->id, 'device_intelligence_location_reported', 'device', null, json_encode(['status' => $data['status']]));

        $this->permissionRequests->resolve($device, PermissionRequest::CAPABILITY_LOCATION, $data['status']);

        return $state;
    }

    /** @return array<string, DeviceIntelligenceFeatureState> keyed by feature */
    public function featureStates(MobileDevice $device): array
    {
        return DeviceIntelligenceFeatureState::query()
            ->where('mobile_device_id', $device->id)
            ->get()
            ->keyBy('feature')
            ->all();
    }

    /**
     * Change-detected telemetry sync — only touches `updated_at`/
     * `telemetry_synced_at` meaningfully when something actually changed,
     * and is expected to be called far less often than the 20s Remote
     * Support heartbeat (the Flutter side only calls this on a real
     * change or periodic coarse interval — see
     * DeviceTelemetryController.dart).
     */
    public function syncTelemetry(MobileDevice $device, array $data): MobileDevice
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);

        $fields = [
            'battery_pct', 'charging', 'battery_saver', 'network_type',
            'screen_on', 'last_screen_active_at', 'storage_total_bytes', 'storage_free_bytes',
            'ram_total_bytes', 'ram_available_bytes', 'wifi_connected', 'vpn_active',
            'device_uptime_seconds',
        ];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $device->{$field} = $data[$field];
            }
        }
        $device->telemetry_synced_at = now();
        $device->save();

        return $device;
    }

    /**
     * Batched, idempotent notification ingestion — `client_notification_key`
     * (Android's `StatusBarNotification.getKey()`) alone is NOT a safe
     * dedup key on its own: Android reuses the SAME key across UPDATES to
     * a still-unread conversation notification (WhatsApp/Messenger/imo all
     * update one notification in place as further messages arrive in the
     * same unread thread, rather than posting a new key per message), so
     * a bare unique(mobile_device_id, client_notification_key) constraint
     * would silently drop every genuinely new message that happened to
     * share an already-stored key — see
     * 2026_09_20_000100_add_content_hash_to_device_notifications_table.php's
     * doc comment. `content_hash` (sha256 of title|body|sender|
     * conversation_title|posted_at) distinguishes distinct message content
     * sharing the same key, while an EXACT retry of the same batch still
     * hashes identically and is still silently skipped by insertOrIgnore
     * on the (mobile_device_id, client_notification_key, content_hash)
     * constraint. A `removed` entry for an already-stored key updates
     * `removed_at` in place (by key alone — a removal has no content of
     * its own to hash) rather than inserting a second row.
     *
     * @param  array<int, array<string, mixed>>  $notifications
     * @return int number of NEW rows actually inserted (for the caller's own "did anything change" telemetry, never surfaced to the tenant)
     */
    public function storeNotifications(MobileDevice $device, array $notifications): int
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);

        $inserted = 0;

        DB::transaction(function () use ($device, $notifications, &$inserted) {
            foreach ($notifications as $n) {
                if (($n['removed'] ?? false) === true) {
                    DeviceNotification::where('mobile_device_id', $device->id)
                        ->where('client_notification_key', $n['client_notification_key'])
                        ->update(['removed_at' => isset($n['posted_at']) ? Carbon::parse($n['posted_at']) : now()]);

                    continue;
                }

                $affected = DeviceNotification::query()->insertOrIgnore([[
                    'tenant_id' => $device->tenant_id,
                    'mobile_device_id' => $device->id,
                    'client_notification_key' => $n['client_notification_key'],
                    'package_name' => $n['package_name'],
                    'app_name' => $n['app_name'] ?? null,
                    'category' => $n['category'] ?? null,
                    'channel_id' => $n['channel_id'] ?? null,
                    'group_key' => $n['group_key'] ?? null,
                    'conversation_title' => $n['conversation_title'] ?? null,
                    'sender' => $n['sender'] ?? null,
                    'title' => isset($n['title']) ? mb_substr($n['title'], 0, 255) : null,
                    'body' => $n['body'] ?? null,
                    'content_hash' => $this->notificationContentHash($n),
                    'posted_at' => Carbon::parse($n['posted_at']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);

                $inserted += $affected;
            }
        });

        return $inserted;
    }

    /**
     * See storeNotifications()'s doc comment — deliberately keyed on the
     * same raw wire values used to build the stored row (title/body/
     * sender/conversation_title/posted_at, all as sent, before the
     * title-length truncation applied to the stored column) so a genuine
     * retry of the exact same batch always hashes identically regardless
     * of that truncation.
     */
    private function notificationContentHash(array $n): string
    {
        return hash('sha256', implode('|', [
            $n['title'] ?? '',
            $n['body'] ?? '',
            $n['sender'] ?? '',
            $n['conversation_title'] ?? '',
            $n['posted_at'] ?? '',
        ]));
    }

    /**
     * Upserts daily per-app usage totals — see the migration's docblock
     * for why this is idempotent by construction (unique per device+
     * package+date, duration simply overwritten with the latest reported
     * total for that day, never accumulated/double-counted across
     * repeated syncs of the same day).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function storeAppUsage(MobileDevice $device, array $rows): int
    {
        abort_if($device->status === MobileDevice::STATUS_REVOKED, 403);

        $now = now();
        $payload = array_map(fn (array $r) => [
            'tenant_id' => $device->tenant_id,
            'mobile_device_id' => $device->id,
            'package_name' => $r['package_name'],
            'app_name' => $r['app_name'] ?? null,
            'usage_date' => $r['usage_date'],
            'duration_seconds' => $r['duration_seconds'],
            'last_used_at' => isset($r['last_used_at']) ? Carbon::parse($r['last_used_at']) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        if (empty($payload)) {
            return 0;
        }

        return DeviceAppUsageDaily::query()->upsert(
            $payload,
            ['mobile_device_id', 'package_name', 'usage_date'],
            ['app_name', 'duration_seconds', 'last_used_at', 'updated_at'],
        );
    }

    public function log(?int $tenantId, ?int $deviceId, string $eventType, string $actorType = 'system', ?int $actorId = null, ?string $note = null): DeviceEvent
    {
        return DeviceEvent::create([
            'tenant_id' => $tenantId,
            'mobile_device_id' => $deviceId,
            'remote_support_session_id' => null,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
