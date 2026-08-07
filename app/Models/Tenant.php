<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'custom_domain_dns_verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->uuid = $tenant->uuid ?: (string) Str::uuid();
            $tenant->trial_ends_at = $tenant->trial_ends_at ?: now()->addDays(7);
        });

        // Auto-provision on signup: default warehouse + store settings.
        // This is what makes the "blank site ready instantly" flow work.
        static::created(function (Tenant $tenant) {
            $tenant->warehouses()->create([
                'name' => 'Main Warehouse',
                'is_default' => true,
            ]);

            $tenant->storeSettings()->createMany([
                ['key' => 'delivery_charge_inside_dhaka',  'value' => '60'],
                ['key' => 'delivery_charge_outside_dhaka', 'value' => '120'],
                ['key' => 'currency', 'value' => 'BDT'],
            ]);
        });
    }

    public function plan()          { return $this->belongsTo(Plan::class); }
    public function subscriptions() { return $this->hasMany(Subscription::class); }
    public function warehouses()    { return $this->hasMany(Warehouse::class); }
    public function storeSettings() { return $this->hasMany(StoreSetting::class); }

    /** Plan limit check, e.g. isWithinLimit('max_products', $count) */
    public function isWithinLimit(string $limit, int $currentCount): bool
    {
        $max = $this->plan->{$limit} ?? null;

        return $max === null || $currentCount < $max;
    }

    public function url(): string
    {
        if (config('app.tenancy_mode', 'subdomain') === 'path') {
            return 'https://' . config('app.central_domain') . '/shop/' . $this->subdomain;
        }

        $domain = ($this->custom_domain_verified && $this->custom_domain)
            ? $this->custom_domain
            : $this->subdomain . '.' . config('app.central_domain');

        return 'https://' . $domain;
    }
}
