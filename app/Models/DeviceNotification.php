<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A captured Android notification — see the migration's docblock. */
class DeviceNotification extends Model
{
    protected $guarded = [];

    /**
     * Deliberately NOT `$casts = ['posted_at' => 'datetime', ...]` —
     * `config('app.timezone')` is `Asia/Dhaka`, and Eloquent's implicit
     * `datetime` cast parses the raw DB string with no explicit timezone,
     * so it assumes that string is already in `app.timezone`. The raw
     * value here is genuinely UTC (see DeviceIntelligenceService::
     * storeNotifications() — the client sends an explicit UTC ISO-8601
     * `posted_at`, and MySQL's session time_zone is SYSTEM/UTC on
     * production, so the stored literal really is UTC) — mislabeling it
     * as Dhaka-local shifted every relative time ("2 minutes ago") 6
     * hours into the past. These accessors parse the same raw value with
     * an explicit `UTC` timezone instead, so the resulting Carbon
     * instance represents the correct absolute instant regardless of the
     * process's global default timezone.
     */
    protected function postedAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? Carbon::parse($value, 'UTC') : null,
        );
    }

    protected function removedAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? Carbon::parse($value, 'UTC') : null,
        );
    }

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }
}
