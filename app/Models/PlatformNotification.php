<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * In-app notification for a brand owner (recipient_type 'owner') or for
 * every Super Admin (recipient_type 'admin', recipient_id NULL).
 * Created only through App\Support\Platform\PlatformNotifier.
 */
class PlatformNotification extends Model
{
    protected $guarded = [];

    protected $casts = ['read_at' => 'datetime'];

    public function scopeForOwner($q, int $ownerId)
    {
        return $q->where('recipient_type', 'owner')->where('recipient_id', $ownerId);
    }

    public function scopeForAdmins($q)
    {
        return $q->where('recipient_type', 'admin');
    }

    public function scopeUnread($q)
    {
        return $q->whereNull('read_at');
    }
}
