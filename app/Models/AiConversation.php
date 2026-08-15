<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One ongoing panel-chat thread per (tenant, user) — see
 * database/sql/chunk33.sql for why this is deliberately not a
 * ChatGPT-style multi-thread sidebar. Created lazily by
 * Tenant\AiChatController on a user's first message.
 */
class AiConversation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public static function tablesReady(): bool
    {
        return Schema::hasTable('ai_conversations') && Schema::hasTable('ai_conversation_messages');
    }

    public function messages()
    {
        return $this->hasMany(AiConversationMessage::class, 'conversation_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
