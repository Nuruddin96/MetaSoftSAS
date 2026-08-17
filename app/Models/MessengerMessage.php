<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MessengerMessage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;

    // $timestamps = false (this table has no updated_at) means Eloquent's
    // getDates() never auto-adds created_at to the Carbon-cast list — it
    // only does that when $timestamps is true. Without this, created_at
    // comes back as a plain string and `$c->created_at?->diffForHumans()`
    // in tenant/messenger/index.blade.php throws "Call to a member
    // function diffForHumans() on string" (the nullsafe operator only
    // guards null, not a non-object string).
    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }

    /**
     * True once database/sql/chunk25.sql's attachment_type/attachment_name
     * columns exist. Mirrors FacebookPage::tablesReady()'s reasoning: any
     * create() call that includes these keys unconditionally throws
     * "Unknown column" the instant either is missing, regardless of whether
     * the message being inserted even has an attachment (the keys are
     * still present in the column list with a null value). Deliberately
     * not memoized — same low-frequency-call-site reasoning as
     * FacebookPage::tablesReady().
     */
    public static function attachmentColumnsReady(): bool
    {
        return Schema::hasColumn('messenger_messages', 'attachment_type')
            && Schema::hasColumn('messenger_messages', 'attachment_name');
    }

    /**
     * True once database/sql/chunk36.sql's sent_by column exists — same
     * additive-column guard as attachmentColumnsReady() above. Used both
     * to gate writing 'sent_by' on create() and to gate reading it back
     * for style learning (App\Services\AI\AiConversationStyleService),
     * which must never assume every row has a reliable value until this
     * column has actually been imported.
     */
    public static function sentByColumnReady(): bool
    {
        return Schema::hasColumn('messenger_messages', 'sent_by');
    }

    /**
     * The resolved Facebook display name for a conversation, if one has
     * ever been captured on any message row for this psid — not just the
     * most recent one. customer_name is only ever set on inbound messages
     * (resolved via MessengerApi::getProfile() the first time a psid is
     * seen); outbound replies/echoes never carry it. Any caller that reads
     * customer_name off a single specific row (especially "the latest
     * message") risks landing on an outbound row and seeing null even
     * though the name was already resolved earlier in the same
     * conversation — this is the one correct way to look it up.
     * withoutGlobalScopes() + explicit tenant_id so this also works from
     * the central Messenger webhook route, where app('currentTenant') is
     * never bound.
     */
    public static function resolvedNameFor(int $tenantId, string $psid): ?string
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sender_psid', $psid)
            ->whereNotNull('customer_name')
            ->orderByDesc('id')
            ->value('customer_name');
    }
}
