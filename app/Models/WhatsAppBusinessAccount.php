<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WhatsAppBusinessAccount extends Model
{
    use BelongsToTenant;

    // "WhatsApp" snake-cases to "whats_app" (Laravel splits at every
    // capital), not "whatsapp" — explicit $table on every model in this
    // feature rather than relying on the naming convention to guess right.
    protected $table = 'whatsapp_business_accounts';

    protected $guarded = [];

    protected $casts = [
        'user_access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
    ];

    public function phoneNumbers()
    {
        // Explicit FK: hasMany()'s default guess is derived from this
        // class's name ("whats_app_business_account_id", same "WhatsApp"
        // snake-casing pitfall as the $table declarations above), not the
        // actual whatsapp_business_account_id column.
        return $this->hasMany(WhatsAppPhoneNumber::class, 'whatsapp_business_account_id');
    }
}
