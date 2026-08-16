<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — see database/sql/chunk38.sql's docblock for the full design.
 * An unresolved row (resolved_at IS NULL) for a (tenant_id, channel,
 * external_id) tuple means the AI Agent must not auto-reply to that
 * conversation — see App\Services\AI\AiHandoffService, the only class
 * that should ever query or write this table directly.
 */
class AiHandoff extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }

    public static function tablesReady(): bool
    {
        return Schema::hasTable('ai_handoffs');
    }
}
