<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Both the confirmation record AND the permanent mutation action log —
 * see database/sql/chunk34.sql's docblock for the full design. Never
 * update()-overwrite tool_name/resolved_args/summary after creation —
 * only status/result/error/confirmed_at should ever change.
 */
class AiPendingAction extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'resolved_args' => 'array',
        'result' => 'array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('ai_pending_actions');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
