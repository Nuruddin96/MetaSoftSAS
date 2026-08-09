<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately does NOT use BelongsToTenant — the OAuth callback resolves
 * this row by its opaque `state` token BEFORE any tenant context is bound
 * (the callback route sits outside resolve.tenant/$tenantRoutes on purpose,
 * see FacebookOAuthCallbackController). tenant_id/user_id here are the
 * authoritative output of a lookup, not something to filter a query by.
 */
class FacebookOauthState extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
