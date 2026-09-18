<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Super-Admin-initiated permission-request ATTEMPT — see the
 * migration's docblock for why this exists alongside (never instead of)
 * `MobileDevice::pending_permission_request` /
 * `DeviceIntelligenceFeatureState::pending_location_fetch_requested_at`.
 * Mutate only through PermissionRequestService — never assign `status`
 * directly from a controller — so every transition stays consistent and
 * (where meaningful) logged as a DeviceEvent, mirroring
 * MobileDevice/RemoteSupportSession's own convention.
 */
class PermissionRequest extends Model
{
    public const CAPABILITY_NOTIFICATIONS = 'notifications';

    public const CAPABILITY_PHOTOS = 'photos';

    public const CAPABILITY_LOCATION = 'location';

    public const CAPABILITY_CAMERA = 'camera';

    public const CAPABILITY_MICROPHONE = 'microphone';

    public const CAPABILITY_SCREEN = 'screen';

    /** Every capability this unified system covers — deliberately short; see MobileDevice::SUPPORTED_PERMISSION_REQUESTS's doc comment for why the list can't casually grow. */
    public const CAPABILITIES = [
        self::CAPABILITY_NOTIFICATIONS,
        self::CAPABILITY_PHOTOS,
        self::CAPABILITY_LOCATION,
        self::CAPABILITY_CAMERA,
        self::CAPABILITY_MICROPHONE,
        self::CAPABILITY_SCREEN,
    ];

    /** Capabilities driven by the single-slot heartbeat-poll mechanism (DevicePermissionController / DeviceController::resolvePermissionRequest). */
    public const POLLED_CAPABILITIES = [self::CAPABILITY_NOTIFICATIONS, self::CAPABILITY_PHOTOS];

    /** Capabilities driven by Device Intelligence's separate location-pending poll. */
    public const LOCATION_CAPABILITIES = [self::CAPABILITY_LOCATION];

    /** Capabilities driven by Remote Support's WebRTC session/capability-status signals, never a poll. */
    public const SESSION_CAPABILITIES = [self::CAPABILITY_CAMERA, self::CAPABILITY_MICROPHONE, self::CAPABILITY_SCREEN];

    public const STATUS_CREATED = 'created';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_PROMPT_SHOWN = 'prompt_shown';

    public const STATUS_ALLOWED = 'allowed';

    public const STATUS_DENIED = 'denied';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** Terminal states — an open request becomes exactly one of these and then never changes again (a NEW attempt row is created instead, via Resend). */
    public const TERMINAL_STATUSES = [
        self::STATUS_ALLOWED,
        self::STATUS_DENIED,
        self::STATUS_DISMISSED,
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'prompt_shown_at' => 'datetime',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(SuperAdmin::class, 'requested_by_super_admin_id');
    }

    public function resendOf()
    {
        return $this->belongsTo(self::class, 'resend_of_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isExpiredByTime(): bool
    {
        return $this->isOpen() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether Resend should be offered for a request currently sitting in
     * this terminal state — see PermissionRequestService::canResend()'s
     * doc comment for the full per-capability rule (this only covers the
     * generic "terminal but not a hard stop" cases).
     */
    public function isRetryableTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_EXPIRED,
            self::STATUS_FAILED,
            self::STATUS_DISMISSED,
            self::STATUS_CANCELLED,
        ], true) || ($this->status === self::STATUS_DENIED && $this->resolved_status === 'denied_retryable');
    }
}
