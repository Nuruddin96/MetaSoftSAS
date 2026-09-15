<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per (device, package, calendar date) — see the migration's docblock. */
class DeviceAppUsageDaily extends Model
{
    protected $table = 'device_app_usage_daily';

    protected $guarded = [];

    protected $casts = [
        'usage_date' => 'date',
        'last_used_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }
}
