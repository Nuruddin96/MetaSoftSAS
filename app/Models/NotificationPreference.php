<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user, per-category push opt-out. Deliberately does NOT use
 * BelongsToTenant — the table has no tenant_id column (see
 * database/sql/chunk31.sql): preferences belong to a user, and a user
 * already belongs to exactly one tenant, so scoping by user_id is both
 * sufficient and tenant-safe as long as callers only ever pass the
 * authenticated user's own id (which is how NotificationPreferenceService
 * uses this model — never an arbitrary client-supplied id).
 */
class NotificationPreference extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
