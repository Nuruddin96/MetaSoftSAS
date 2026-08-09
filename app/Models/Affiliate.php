<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class Affiliate extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['password' => 'hashed'];

    protected static function booted(): void
    {
        static::creating(function (Affiliate $a) {
            if (empty($a->referral_code)) {
                do {
                    $code = 'AFF'.strtoupper(Str::random(6));
                } while (self::where('referral_code', $code)->exists());
                $a->referral_code = $code;
            }
        });
    }

    public function commissions()
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function serviceLeads()
    {
        return $this->hasMany(ServiceLead::class);
    }

    public function referredTenants()
    {
        return $this->hasMany(Tenant::class, 'referred_by_affiliate_id');
    }
}
