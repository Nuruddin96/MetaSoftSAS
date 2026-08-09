<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MessengerMessage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;

    // $timestamps = false (this table has no updated_at) means Eloquent's
    // getDates() never auto-adds created_at to the Carbon-cast list — it
    // only does that when $timestamps is true. Without this, created_at
    // comes back as a plain string and `$c->created_at?->diffForHumans()`
    // in tenant/messenger/index.blade.php throws "Call to a member
    // function diffForHumans() on string" (the nullsafe operator only
    // guards null, not a non-object string).
    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }
}
