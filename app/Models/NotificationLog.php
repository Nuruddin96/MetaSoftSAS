<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable record of a push notification actually sent — deliberately not
 * named Notification, to avoid any confusion with Laravel's own
 * notification system (unused in this app; see WebPushService's docblock
 * for why a plain queued job + this log is used instead). Table is
 * `notifications` — see database/sql/chunk31.sql.
 */
class NotificationLog extends Model
{
    use BelongsToTenant;

    protected $table = 'notifications';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function markRead(): void
    {
        if (! $this->read_at) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
