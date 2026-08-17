<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class WhatsAppPhoneNumber extends Model
{
    use BelongsToTenant;

    // "WhatsApp" snake-cases to "whats_app" (Laravel splits at every
    // capital), not "whatsapp" — explicit $table on every model in this
    // feature rather than relying on the naming convention to guess right.
    protected $table = 'whatsapp_phone_numbers';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'subscribed_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function businessAccount()
    {
        return $this->belongsTo(WhatsAppBusinessAccount::class, 'whatsapp_business_account_id');
    }

    /**
     * True once database/sql/chunk26.sql is fully imported. Mirrors
     * FacebookPage::tablesReady()'s reasoning: any call site that queries or
     * writes these tables before the schema exists must degrade to a
     * friendly "not ready yet" message instead of a raw SQL error. Unlike
     * chunk23.sql, chunk26.sql doesn't ALTER any pre-existing table — all
     * four tables are new — but a shared-hosting SQL import can still abort
     * partway through a multi-statement file (e.g. a later CREATE TABLE
     * fails while earlier ones already committed), so checking all four
     * tables individually, rather than assuming "the file ran or it didn't,"
     * is the same defensive posture, not a redundant one.
     *
     * Deliberately not memoized — same low-frequency-call-site reasoning as
     * FacebookPage::tablesReady() (one Settings load, one webhook event, one
     * inbox action per request, never a hot loop).
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('whatsapp_oauth_states')
            && Schema::hasTable('whatsapp_business_accounts')
            && Schema::hasTable('whatsapp_phone_numbers')
            && Schema::hasTable('whatsapp_messages');
    }
}
