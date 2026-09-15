<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately does NOT use BelongsToTenant — the plugin handshake resolves
 * this row by its opaque `token` BEFORE any tenant context is bound (the
 * handshake route is a central API route, no resolve.tenant in front of
 * it). tenant_id/user_id here are the authoritative output of a lookup,
 * not something to filter a query by. Same shape as FacebookOauthState.
 */
class WordPressConnectionToken extends Model
{
    // Eloquent's default table-name inference naively snake_cases on every
    // capital letter — "WordPressConnectionToken" would become
    // "word_press_connection_tokens", not "wordpress_connection_tokens"
    // (chunk59.sql). Explicit override, same fix WordPressConnection needs.
    protected $table = 'wordpress_connection_tokens';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
