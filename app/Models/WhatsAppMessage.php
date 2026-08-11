<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    use BelongsToTenant;

    // "WhatsApp" snake-cases to "whats_app" (Laravel splits at every
    // capital), not "whatsapp" — explicit $table on every model in this
    // feature rather than relying on the naming convention to guess right.
    protected $table = 'whatsapp_messages';

    protected $guarded = [];

    public $timestamps = false;

    // Same reasoning as MessengerMessage: $timestamps = false means
    // Eloquent never auto-adds created_at to the Carbon-cast list, so it
    // must be cast explicitly or it comes back as a plain string.
    protected $casts = [
        'created_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->created_at = $m->created_at ?: now());
    }

    public function phoneNumber()
    {
        return $this->belongsTo(WhatsAppPhoneNumber::class, 'whatsapp_phone_number_id');
    }

    /**
     * The Cloud API media id for an inbound media message, read straight out
     * of raw_payload (e.g. $raw_payload['image']['id']) — no dedicated
     * column for it, same "raw_payload is the full record" reasoning
     * WhatsAppWebhookController::extractContent() already documents for
     * location/contacts. Null for outbound rows (they carry a real
     * attachment_url already, never a Meta media id) and for any inbound
     * type that isn't actually media (text, location, etc.).
     */
    public function inboundMediaId(): ?string
    {
        if ($this->direction !== 'in' || ! $this->attachment_type) {
            return null;
        }

        return data_get($this->raw_payload, "{$this->message_type}.id");
    }

    /**
     * The resolved WhatsApp display name for a conversation, if one has
     * ever been captured on any message row for this wa_id — not just the
     * most recent one. Same "latest row might not carry it" caution as
     * MessengerMessage::resolvedNameFor(), kept here for parity even though
     * WhatsApp's webhook payload includes contacts[].profile.name on inbound
     * messages more consistently than Messenger's profile-fetch-on-first-
     * contact approach: an outbound row still never carries a customer name,
     * so any caller reading a single "latest message" row risks landing on
     * one of those. withoutGlobalScopes() + explicit tenant_id so this also
     * works from the future webhook route, where app('currentTenant') is
     * never bound (same reasoning as the Messenger webhook).
     */
    public static function resolvedNameFor(int $tenantId, string $waId): ?string
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('wa_id', $waId)
            ->whereNotNull('customer_name')
            ->orderByDesc('id')
            ->value('customer_name');
    }
}
