<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant Remote Support on/off switch — Super Admin only, see
 * RemoteSupportService::setTenantEnabled(). Row existence + `enabled` is
 * the gate; a disabled/missing row means Remote Support is completely
 * invisible to that tenant's app (no device can reach `on_ready` — see
 * MobileDevice's docblock).
 */
class RemoteSupportSetting extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function enabledBy()
    {
        return $this->belongsTo(SuperAdmin::class, 'enabled_by_super_admin_id');
    }

    public function disabledBy()
    {
        return $this->belongsTo(SuperAdmin::class, 'disabled_by_super_admin_id');
    }
}
