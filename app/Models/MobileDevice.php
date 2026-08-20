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
}
