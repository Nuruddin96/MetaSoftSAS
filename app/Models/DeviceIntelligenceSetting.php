<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tenant-level master toggle for Device Intelligence — see the migration's docblock. Mirrors RemoteSupportSetting exactly but is a separate table/module. */
class DeviceIntelligenceSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
