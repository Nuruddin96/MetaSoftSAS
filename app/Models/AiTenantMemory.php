<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * "Teach Your AI Agent" — one tenant-authored Q&A pair (database/sql/
 * chunk41.sql), e.g. "What is the delivery charge inside Dhaka?" / "60
 * BDT." Stored verbatim — Tenant\AiMemoryController never calls OpenAI to
 * save one. Matching a real customer message against saved questions
 * happens later, at AI reply time, in App\Services\AI\AiTenantMemoryService.
 */
class AiTenantMemory extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_ai_memories';

    protected $guarded = [];

    /**
     * Additive-table guard (database/sql/chunk41.sql) — same pattern as
     * FacebookPage::tablesReady()/WhatsAppPhoneNumber::tablesReady(): a
     * deploy that lands before this chunk is imported must degrade to
     * "no saved memories yet" everywhere (Settings page, AI context
     * pipeline) instead of a raw SQL error.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('tenant_ai_memories');
    }
}
