<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately does NOT use BelongsToTenant — same reasoning as
 * FacebookOauthState: the Embedded Signup callback resolves this row by its
 * opaque `state` token before any tenant context is bound. tenant_id/user_id
 * here are the authoritative output of that lookup, not something to filter
 * a query by.
 */
class WhatsAppOauthState extends Model
{
    // "WhatsApp" snake-cases to "whats_app" (Laravel splits at every
    // capital), not "whatsapp" — explicit $table on every model in this
    // feature rather than relying on the naming convention to guess right.
    protected $table = 'whatsapp_oauth_states';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
