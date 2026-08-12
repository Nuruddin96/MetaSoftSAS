<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant that has the Advertising module switched on. Row
 * existence + is_active is the per-tenant activation switch (mirrors
 * messenger_settings for the Messenger module) — combined with
 * Tenant->plan->allow_meta_ads as a plan-tier lever, this is the full
 * gate checked by AdvertisingBalanceService::isEnabled().
 *
 * `balance` is a cached snapshot, mutated only by AdvertisingBalanceService
 * inside the same DB transaction as the ad_billing_ledger row that
 * justifies the change — never written directly anywhere else.
 */
class AdBillingAccount extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'balance' => 'decimal:2',
        'daily_budget' => 'decimal:2',
        'billing_rate' => 'decimal:4',
        'low_balance_threshold' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * True once database/sql/chunk29.sql is imported — same purpose as
     * FacebookPage::tablesReady() / WhatsAppPhoneNumber::tablesReady() in
     * this codebase. panel.blade.php renders on every tenant page load and
     * queries this table for the header chip, so a deploy that lands
     * before the SQL import must degrade to "module not enabled" instead
     * of a raw "table doesn't exist" SQL error for every tenant.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('ad_billing_accounts') && Schema::hasTable('ad_billing_ledger');
    }
}
