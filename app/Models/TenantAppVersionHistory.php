<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * One row per contiguous run of a tenant/user reporting the same
 * (app_version, app_build) — see the 2026_09_17_020000_create_tenant_app_
 * version_history_table migration's own docblock for the full model.
 * Never tenant-scoped (BelongsToTenant): Super Admin's console queries
 * across every tenant, same as TenantAppVersion itself.
 */
class TenantAppVersionHistory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'app_build' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('tenant_app_version_history');
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
