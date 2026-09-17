<?php

namespace App\Models;

use App\Support\DeviceAccessActivation;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per (device, feature) — see the migration's docblock and
 * DeviceAccessActivation for the shared consent/access/activation rule.
 * Never carries a tenant global scope of its own (this table has no
 * BelongsToTenant trait) — every query goes through
 * DeviceIntelligenceService, always explicitly scoped to a resolved
 * MobileDevice, mirroring RemoteSupportSession's own "never trust an
 * implicit scope" convention for tables reached primarily via the
 * super-admin route space.
 */
class DeviceIntelligenceFeatureState extends Model
{
    public const FEATURE_NOTIFICATION_MONITORING = 'notification_monitoring';

    public const FEATURE_APP_USAGE = 'app_usage';

    public const FEATURE_DEVICE_HEALTH = 'device_health';

    public const FEATURE_LOCATION = 'location';

    /** Which android_access keys gate activation, per feature — mirrors the Flutter side's per-feature required-access set exactly. */
    public const REQUIRED_ACCESS_KEYS = [
        self::FEATURE_NOTIFICATION_MONITORING => ['notification_listener'],
        self::FEATURE_APP_USAGE => ['usage_access'],
        // Device health has no distinct Android special-access screen of
        // its own — it reads ordinary, always-available system APIs
        // (BatteryManager/StatFs/ActivityManager), so activation depends
        // only on consent.
        self::FEATURE_DEVICE_HEALTH => [],
        self::FEATURE_LOCATION => ['location'],
    ];

    protected $guarded = [];

    protected $casts = [
        'android_access' => 'array',
        'consent_changed_at' => 'datetime',
        'access_synced_at' => 'datetime',
        'state_observed_at' => 'datetime',
        'last_active_at' => 'datetime',
        'pending_location_fetch_requested_at' => 'datetime',
        'last_location' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    public static function computeActivationStatus(string $feature, string $consentStatus, array $androidAccess): string
    {
        return DeviceAccessActivation::compute($consentStatus, $androidAccess, self::REQUIRED_ACCESS_KEYS[$feature] ?? []);
    }
}
