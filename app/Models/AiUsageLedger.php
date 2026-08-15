<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable transaction ledger for the AI Agent credit system — every row
 * is created once by AiCreditService and never updated or deleted.
 * estimated_cost_usd/created_by are admin-only; tenant-facing
 * controllers/views must never select or display them (see
 * TENANT_VISIBLE_COLUMNS).
 */
class AiUsageLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'ai_usage_ledger';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'credit_amount' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'estimated_cost_usd' => 'decimal:6',
        // Explicit despite $timestamps = false (which only controls
        // auto-population on save, not read casting) — every view calls
        // ->timezone()/->format() on created_at.
        'created_at' => 'datetime',
    ];

    /** Columns safe to expose to a tenant — everything except admin-only cost/token data. */
    public const TENANT_VISIBLE_COLUMNS = [
        'id', 'tenant_id', 'type', 'credit_amount', 'balance_after', 'note', 'created_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }

    public function admin()
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }
}
