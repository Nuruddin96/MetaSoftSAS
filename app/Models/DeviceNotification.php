<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A captured Android notification — see the migration's docblock. */
class DeviceNotification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'posted_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }
}
