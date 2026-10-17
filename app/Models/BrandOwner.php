<?php

namespace App\Models;

use App\Notifications\BrandOwnerResetPassword;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * A brand owner's login (guard `brand_owner`, database/sql/chunk64.sql).
 * One account owns one brand — the owner dashboard is built around
 * "My Brand". Owners can never touch verification, featuring, sponsorship,
 * awards results or vote counts; those live behind auth:super_admin only.
 */
class BrandOwner extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    public function brand()
    {
        return $this->hasOne(Brand::class);
    }

    public function notifications()
    {
        return $this->hasMany(PlatformNotification::class, 'recipient_id')->where('recipient_type', 'owner');
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** Self-service reset email (auth.passwords.brand_owners) — links to the owner reset page. */
    public function sendPasswordResetNotification($token): void
    {
        NotificationFacade::route('mail', $this->email)->notify(new BrandOwnerResetPassword($token, $this->email));
    }
}
