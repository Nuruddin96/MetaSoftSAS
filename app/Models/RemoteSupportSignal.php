<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** No `updated_at` — a signal message is write-once, append-only. See the migration's docblock for the polling-transport rationale. */
class RemoteSupportSignal extends Model
{
    use BelongsToTenant;

    public const SENDER_ADMIN = 'admin';

    public const SENDER_DEVICE = 'device';

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function session()
    {
        return $this->belongsTo(RemoteSupportSession::class, 'remote_support_session_id');
    }
}
