<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One row per registered FCM device token — the native-app counterpart to
 * [PushSubscription] (browser Web Push). See database/sql/chunk63.sql for
 * why this is a separate table from `mobile_devices` (Remote Support only).
 */
class DevicePushToken extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Same additive-table guard [PushSubscription::tablesReady()] uses —
     * database/sql/chunk63.sql may not be imported into a given environment
     * yet, and code that touches this table must degrade gracefully rather
     * than 500 until it is.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('device_push_tokens');
    }
}
