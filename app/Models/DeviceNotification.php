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

    /**
     * Safety net for the direct `::create()` path (test fixtures, any
     * future direct usage) — the real production write path
     * (DeviceIntelligenceService::storeNotifications()) uses a raw
     * `insertOrIgnore()` bulk insert, which bypasses Eloquent events
     * entirely and already computes `content_hash` itself, so this never
     * runs twice for the same row. See that service method's doc comment
     * for why this column exists at all.
     */
    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            if ($notification->content_hash === null) {
                // Reads the RAW attribute (never the postedAt() accessor
                // above) — that accessor assumes an already-persisted
                // string value from the DB and isn't meant to run against
                // whatever a caller passed into create() (string, Carbon,
                // etc.) before this model has ever been saved.
                $rawPostedAt = $notification->getAttributes()['posted_at'] ?? '';

                $notification->content_hash = hash('sha256', implode('|', [
                    $notification->title ?? '',
                    $notification->body ?? '',
                    $notification->sender ?? '',
                    $notification->conversation_title ?? '',
                    is_string($rawPostedAt) ? $rawPostedAt : (string) $rawPostedAt,
                ]));
            }
        });
    }

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }
}
