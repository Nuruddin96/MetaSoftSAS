<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant/user reporting the Business App version currently
 * installed — see the 2026_09_17_010000_create_tenant_app_versions_table
 * migration's own docblock for why this is its own table. Never
 * tenant-scoped via BelongsToTenant: Super Admin's console
 * (SuperAdmin\TenantAppVersionController) must query across every
 * tenant, exactly like AppUpdateConfig/PlatformAnnouncement.
 */
class TenantAppVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'app_build' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('tenant_app_versions');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
