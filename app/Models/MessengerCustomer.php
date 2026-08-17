<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Facebook Messenger customer identity (name + profile photo) —
 * see database/sql/chunk28.sql for the schema rationale. tenant_id +
 * facebook_page_id + psid never carry an access token; the Page's token
 * stays on facebook_pages.page_access_token (encrypted), looked up
 * separately wherever a Graph call is actually made.
 */
class MessengerCustomer extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'profile_pic_fetched_at' => 'datetime',
        'identity_fetched_at' => 'datetime',
    ];

    /** True once database/sql/chunk28.sql is imported — same tablesReady() guard pattern every other additive table in this codebase uses (FacebookPage, WhatsAppPhoneNumber, ...). Deliberately not memoized, same reasoning as FacebookPage::tablesReady(). */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('messenger_customers');
    }

    /** Stored full name if set, else first_name+last_name — null if neither is available (never "Unknown Customer" itself; that fallback belongs to the caller, which also has a message-level customer_name to fall through to first — see displayNameFor()). */
    public function getDisplayNameAttribute(): ?string
    {
        return $this->name ?: (trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: null);
    }

    /**
     * The ONE canonical display-name resolution order for a Messenger
     * conversation, per the identity system's design: stored Facebook
     * identity first, then whatever customer_name a message row already
     * carries (messenger_messages.customer_name, unchanged/still written on
     * every inbound message), "অজানা কাস্টমার" only as the last resort.
     * Every read site (unified inbox list, conversation header, the legacy
     * channel-only list) calls this rather than reimplementing the chain,
     * so there is exactly one place that decides what a customer is called.
     */
    public static function displayNameFor(?self $identity, ?string $messageCustomerName): string
    {
        return $identity?->display_name ?: $messageCustomerName ?: 'অজানা কাস্টমার';
    }
}
