<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central reference data (like Plan) — never tenant-scoped, no
 * BelongsToTenant. The fixed list a tenant picks from in onboarding Step 1;
 * see database/sql/chunk52.sql for the seeded Bangladesh-focused taxonomy
 * and App\Services\Tenant\TenantOnboardingService for how a selection
 * seeds a tenant's own starter categories.
 */
class BusinessType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'default_attributes' => 'array',
        'is_active' => 'bool',
    ];

    public function categories()
    {
        return $this->hasMany(BusinessTypeCategory::class)->orderBy('sort_order');
    }
}
