<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

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

    /**
     * True once database/sql/chunk25.sql's attachment_type/attachment_name
     * columns exist. Mirrors FacebookPage::tablesReady()'s reasoning: any
     * create() call that includes these keys unconditionally throws
     * "Unknown column" the instant either is missing, regardless of whether
     * the message being inserted even has an attachment (the keys are
     * still present in the column list with a null value). Deliberately
     * not memoized — same low-frequency-call-site reasoning as
     * FacebookPage::tablesReady().
     */
    public static function attachmentColumnsReady(): bool
    {
        return Schema::hasColumn('messenger_messages', 'attachment_type')
            && Schema::hasColumn('messenger_messages', 'attachment_name');
    }
}
