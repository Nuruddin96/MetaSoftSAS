<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One user-visible turn (role='user' or 'assistant') in an AiConversation
 * — see chunk33.sql for why intermediate tool-calling messages are never
 * stored here.
 */
class AiConversationMessage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }

    public function conversation()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** Non-null only for the one assistant message that proposed a mutation — see chunk34.sql. */
    public function pendingAction()
    {
        return $this->belongsTo(AiPendingAction::class, 'pending_action_id');
    }
}
