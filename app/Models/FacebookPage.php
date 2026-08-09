<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class FacebookPage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'page_access_token' => 'encrypted',
        'is_active' => 'boolean',
        'subscribed_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function connection()
    {
        return $this->belongsTo(FacebookConnection::class, 'facebook_connection_id');
    }

    /**
     * True once every piece of database/sql/chunk23.sql is in place — the
     * three new tables AND the messenger_messages.facebook_page_id column
     * chunk23.sql also adds via a separate ALTER TABLE statement. Both are
     * required: a partial import (e.g. the three CREATE TABLEs succeeded
     * but the ALTER on the pre-existing messenger_messages table failed or
     * was skipped) must be treated exactly like "not imported at all,"
     * because code that references facebook_page_id in a query OR an
     * INSERT/UPDATE column list throws a SQL error the moment that column
     * doesn't exist — regardless of whether the three new tables exist.
     *
     * The OAuth flow is additive/optional on top of the pre-existing
     * messenger_settings system — call sites that would otherwise touch
     * these tables/column unconditionally must check this first, so a
     * deploy that lands before chunk23.sql is fully imported degrades to
     * "OAuth not yet available" instead of a raw SQL error for every
     * tenant, including ones still on the legacy manual-token flow.
     * Deliberately not memoized: these are low-frequency call sites (one
     * Settings load, one webhook event, one inbox action) and a fresh check
     * avoids ever returning a stale "not ready" result after the schema is
     * completed mid-request-cycle.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('facebook_connections')
            && Schema::hasTable('facebook_pages')
            && Schema::hasTable('facebook_oauth_states')
            && Schema::hasTable('messenger_messages')
            && Schema::hasColumn('messenger_messages', 'facebook_page_id');
    }
}
