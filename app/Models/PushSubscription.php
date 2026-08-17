<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PushSubscription extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Same additive-table guard every other post-schema.sql feature in this
     * app uses (see FacebookPage/WhatsAppPhoneNumber/AiAgentMessageJob) —
     * database/sql/chunk31.sql may not be imported into a given environment
     * yet, and code that touches these tables must degrade gracefully
     * rather than 500 until it is.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('push_subscriptions')
            && Schema::hasTable('notifications')
            && Schema::hasTable('notification_preferences');
    }
}
