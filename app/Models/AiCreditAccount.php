<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant that has ever been allocated AI credit. Row
 * existence + balance > 0 is the entire "can this tenant's AI Agent make
 * an OpenAI call right now" gate — see AiCreditService::hasCredit(). No
 * separate is_active switch (unlike AdBillingAccount): exhausting credit
 * must never look like a destroyed/disabled configuration (see
 * ai_agent_enabled/messenger_ai_auto_reply_enabled in store_settings,
 * which are completely independent of this table) — it's just balance
 * reaching zero, recoverable the moment Super Admin allocates more.
 *
 * `balance` is a cached snapshot, mutated only by AiCreditService inside
 * the same DB transaction as the ai_usage_ledger row that justifies the
 * change — never written directly anywhere else.
 */
class AiCreditAccount extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'balance' => 'decimal:4',
    ];

    /**
     * True once database/sql/chunk32.sql is imported — same purpose as
     * AdBillingAccount::tablesReady() / AiAgentMessageJob::tablesReady().
     * A deploy that lands before the SQL import must degrade to "no
     * credit" instead of a raw "table doesn't exist" SQL error.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('ai_credit_accounts') && Schema::hasTable('ai_usage_ledger');
    }
}
