<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * See the migration's docblock for the full trust-tier explanation.
 * `status` follows docs/device-lifecycle.md's state machine exactly —
 * mutate it only through RemoteSupportService, never by assigning the
 * column directly from a controller, so every transition also gets a
 * DeviceEvent row.
 */
class MobileDevice extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_OFF = 'off';

    public const STATUS_ON_NOT_READY = 'on_not_ready';

    public const STATUS_ON_READY = 'on_ready';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_REVOKED = 'revoked';

    /** Heartbeat gap beyond this many seconds flips a device to OFFLINE (device-lifecycle.md: "3 missed 60s intervals"). */
    public const OFFLINE_AFTER_SECONDS = 180;

    // --- App consent / Android access sync (see
    // docs/remote-support-consent-model.md on the Flutter side and
    // database/migrations/2026_09_14_000000_add_consent_access_state_to_mobile_devices_table.php)
    // — a SEPARATE layer from remote_support_enabled/permissions above,
    // never collapsed into it. ---------------------------------------

    public const CONSENT_NOT_ASKED = 'not_asked';

    public const CONSENT_ENABLED = 'enabled';

    public const CONSENT_DISABLED = 'disabled';

    public const ACCESS_GRANTED = 'granted';

    public const ACCESS_DENIED = 'denied';

    public const ACCESS_RESTRICTED = 'restricted';

    public const ACCESS_NOT_SUPPORTED = 'not_supported';

    public const ACCESS_NOT_REQUESTED = 'not_requested';

    public const ACTIVATION_INACTIVE = 'inactive';

    public const ACTIVATION_WAITING_FOR_ANDROID_ACCESS = 'waiting_for_android_access';

    public const ACTIVATION_DISABLED_BY_TENANT = 'disabled_by_tenant';

    public const ACTIVATION_ACTIVE = 'active';

    /** The two android_access keys that gate activation — mirrors RemoteSupportAccessSnapshot.requiredAndroidAccessGranted on the Flutter side exactly (camera/microphone/screen_capture stay best-effort, never gating). */
    public const REQUIRED_ACCESS_KEYS = ['notifications', 'battery_optimization_exempt'];

    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'remote_support_enabled' => 'boolean',
        'charging' => 'boolean',
        'foreground_service_running' => 'boolean',
        'permissions' => 'array',
        'android_access' => 'array',
        'consent_changed_at' => 'datetime',
        'access_synced_at' => 'datetime',
        'state_observed_at' => 'datetime',
        'remote_support_last_active_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(SuperAdmin::class, 'approved_by_super_admin_id');
    }

    public function revokedBy()
    {
        return $this->belongsTo(SuperAdmin::class, 'revoked_by_super_admin_id');
    }

    public function sessions()
    {
        return $this->hasMany(RemoteSupportSession::class);
    }

    public function events()
    {
        return $this->hasMany(DeviceEvent::class);
    }

    /**
     * A heartbeat gap doesn't need the device to explicitly announce going
     * offline (device-lifecycle.md) — this is evaluated live wherever
     * "is this device actually reachable right now" matters (the Super
     * Admin device list, session-start eligibility), rather than relying
     * on a scheduled job to flip the stored `status` column, so the
     * displayed state is never stale between heartbeats.
     */
    public function isHeartbeatFresh(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    /** Live status for display — overrides the stored `status` with `offline` when the heartbeat has lapsed. */
    public function liveStatus(): string
    {
        if (in_array($this->status, [self::STATUS_REVOKED, self::STATUS_PENDING_VERIFICATION, self::STATUS_OFF], true)) {
            return $this->status;
        }

        return $this->isHeartbeatFresh() ? $this->status : self::STATUS_OFFLINE;
    }

    /**
     * All three trust tiers required simultaneously (docs/security-model.md
     * §3) except the fourth — MediaProjection consent — which only exists
     * on-device at the moment of capture and can never be represented by a
     * server-side flag; see RemoteSupportService::startSession()'s
     * docblock.
     */
    public function isEligibleForSession(): bool
    {
        return $this->status !== self::STATUS_REVOKED
            && $this->remote_support_enabled
            && $this->liveStatus() === self::STATUS_ON_READY;
    }

    /**
     * Pure function — the SAME rule as `remoteSupportActivationFor` in
     * remote_support_access_state.dart on the Flutter side, kept in exact
     * sync intentionally (see that function's doc comment for the full
     * rationale). Never called directly by a controller; always go through
     * RemoteSupportService::syncConsentState() so the result is persisted
     * and logged consistently. Exposed as a static, side-effect-free method
     * (rather than reading $this->android_access) so it's trivially
     * unit-testable against the full consent × access matrix without a
     * database row.
     *
     * @param  array<string, string>  $androidAccess  keyed by
     *                                                 REQUIRED_ACCESS_KEYS entries (and optionally camera/microphone/screen_capture, which never affect the result)
     */
    public static function computeActivationStatus(string $consentStatus, array $androidAccess): string
    {
        $requiredGranted = true;
        foreach (self::REQUIRED_ACCESS_KEYS as $key) {
            if (($androidAccess[$key] ?? self::ACCESS_NOT_REQUESTED) !== self::ACCESS_GRANTED) {
                $requiredGranted = false;
                break;
            }
        }

        if ($consentStatus === self::CONSENT_ENABLED) {
            return $requiredGranted ? self::ACTIVATION_ACTIVE : self::ACTIVATION_WAITING_FOR_ANDROID_ACCESS;
        }

        return $requiredGranted ? self::ACTIVATION_DISABLED_BY_TENANT : self::ACTIVATION_INACTIVE;
    }
}
