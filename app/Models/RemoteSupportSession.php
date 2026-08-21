<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class RemoteSupportSession extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    protected $guarded = [];

    protected $casts = [
        'include_microphone' => 'boolean',
        'include_camera' => 'boolean',
        'started_at' => 'datetime',
        'connected_at' => 'datetime',
        'ended_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    public function startedBy()
    {
        return $this->belongsTo(SuperAdmin::class, 'started_by_super_admin_id');
    }

    public function signals()
    {
        return $this->hasMany(RemoteSupportSignal::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * A liveness check, deliberately separate from and much shorter than
     * [isExpired] — see config('remote_support.abandoned_session_grace_seconds')'s
     * doc comment for the real on-device failure mode this recovers from.
     * `connected_at` only gets set once the admin's WebRTC answer actually
     * lands (RemoteSupportService::pushSignal()); a session that never
     * reaches that within the grace window was never live in the first
     * place — the device process died (or otherwise failed) before ever
     * negotiating, not merely slow.
     */
    public function isLikelyAbandoned(): bool
    {
        return $this->connected_at === null
            && $this->started_at->copy()->addSeconds((int) config('remote_support.abandoned_session_grace_seconds'))->isPast();
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_ENDED && ! $this->isExpired();
    }
}
