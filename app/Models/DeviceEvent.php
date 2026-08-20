<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit log — written exclusively by
 * RemoteSupportService::log(), never directly by a controller. Deliberately
 * does NOT use BelongsToTenant: it must remain readable/writable from the
 * Super Admin console where no `currentTenant` is ever bound, and every
 * write already carries an explicit tenant_id from the caller.
 */
class DeviceEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'mobile_device_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function session()
    {
        return $this->belongsTo(RemoteSupportSession::class, 'remote_support_session_id');
    }
}
