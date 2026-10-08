<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only audit trail for the recognition platform: who changed what,
 * when and why. Every Super Admin mutation of brands, awards, campaigns and
 * votes goes through record().
 */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['changes' => 'array'];

    public static function record(string $action, Model $subject, ?array $changes = null, ?string $reason = null): self
    {
        $admin = Auth::guard('super_admin')->user();
        $owner = $admin ? null : Auth::guard('brand_owner')->user();

        return self::create([
            'actor_type' => $admin ? 'admin' : ($owner ? 'owner' : 'system'),
            'actor_id' => $admin?->id ?? $owner?->id,
            'actor_name' => $admin?->name ?? $owner?->name ?? 'system',
            'action' => $action,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'ip' => request()?->ip(),
        ]);
    }
}
