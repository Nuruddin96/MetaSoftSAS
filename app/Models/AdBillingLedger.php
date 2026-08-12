<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable transaction ledger for the Advertising module — every row is
 * created once by AdvertisingBalanceService and never updated or deleted.
 * meta_spend_usd/charge_date/created_by are admin-only; tenant-facing
 * controllers/views must never select or display them.
 */
class AdBillingLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'ad_billing_ledger';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'meta_spend_usd' => 'decimal:2',
        'charge_date' => 'date',
        // Explicit despite $timestamps = false (which only controls
        // auto-population on save, not read casting) — every view calls
        // ->timezone()/->format() on created_at.
        'created_at' => 'datetime',
    ];

    /** Columns safe to expose to a tenant — everything except admin-only cost data. */
    public const TENANT_VISIBLE_COLUMNS = [
        'id', 'tenant_id', 'type', 'amount', 'balance_after', 'note', 'created_at',
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
